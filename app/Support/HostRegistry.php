<?php

namespace App\Support;

use App\Models\Domain;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EZ-REL-20261016-3 — one place that answers "what is this host?".
 *
 * Source of truth: table `admindomains` (status 'Active' or 'Routed' = live).
 * Safety net: config('fleet.fallback_domains') / config('fleet.fixed_hosts'),
 * so a missing row or a DB outage never takes a current domain offline.
 * All host comparison is done in ASCII (punycode), lower case.
 */
class HostRegistry
{
    public const CACHE_KEY = 'fleet.registry.v2';   // -7: bumped (old cached rows are simply ignored)
    public const LIVE = ['Active', 'Routed'];

    public static function ascii(?string $host): string
    {
        $h = strtolower(trim((string) $host, " .\t\n\r"));
        if ($h === '') return '';
        if (preg_match('/[^\x20-\x7e]/', $h) && function_exists('idn_to_ascii')) {
            $a = idn_to_ascii($h, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($a) $h = strtolower($a);
        }
        return $h;
    }

    public static function utf8(string $host): string
    {
        if (str_contains($host, 'xn--') && function_exists('idn_to_utf8')) {
            $u = idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($u) return $u;
        }
        return $host;
    }

    /** @return array<string,array{kind:string,sso:bool,live:bool}> keyed by ascii domain */
    public static function all(): array
    {
        $rows = [];
        try {
            $rows = Cache::remember(self::CACHE_KEY, (int) config('fleet.cache_ttl', 300), function () {
                if (!Schema::hasTable('admindomains')) return [];
                $hasKind = Schema::hasColumn('admindomains', 'kind');
                $hasSso = Schema::hasColumn('admindomains', 'sso_enabled');
                $out = [];
                foreach (DB::table('admindomains')->get() as $r) {
                    $d = self::ascii($r->domain ?? '');
                    if ($d === '') continue;
                    $out[$d] = [
                        'kind' => $hasKind ? ((string) ($r->kind ?: 'fleet')) : 'fleet',
                        'sso' => $hasSso ? (bool) $r->sso_enabled : true,
                        'live' => in_array((string) $r->status, self::LIVE, true),
                    ];
                }
                return $out;
            });
        } catch (\Throwable $e) {
            $rows = [];
        }
        // EZ-REL-20261016-7: the original fleet (config fleet.fallback_domains) and the fixed hosts
        // are ALWAYS routed, whatever their admindomains row says. admindomains.status only drives
        // the for-sale / domain dropdowns. New shipyard domains (not in config) still need a row
        // with status Active or Routed. The row may still set sso_enabled for a fallback domain.
        foreach ((array) config('fleet.fallback_domains', []) as $d) {
            $d = self::ascii($d);
            $rows[$d] = ['kind' => 'fleet', 'sso' => $rows[$d]['sso'] ?? true, 'live' => true];
        }
        foreach ((array) config('fleet.fixed_hosts', []) as $d) {
            $d = self::ascii($d);
            $rows[$d] = ['kind' => 'fixed', 'sso' => false, 'live' => true];
        }
        return $rows;
    }

    public static function flush(): void
    {
        try { Cache::forget(self::CACHE_KEY); } catch (\Throwable $e) {}
    }

    public static function isAppHost(string $host): bool
    {
        $h = self::ascii($host);
        foreach ((array) config('fleet.app_hosts', []) as $a) {
            if ($h === self::ascii($a)) return true;
        }
        return false;
    }

    /**
     * Resolve a host.
     * @return array{type:string,host:string,fleet:?string,label:?string,kind:?string,sso:bool}
     *   type: 'app' | 'apex' | 'fixed' | 'sub' | 'unknown' | 'inactive'
     */
    public static function resolve(string $host): array
    {
        $h = self::ascii($host);
        $res = ['type' => 'unknown', 'host' => $h, 'fleet' => null, 'label' => null, 'kind' => null, 'sso' => false];
        if ($h === '') return $res;
        if (self::isAppHost($h)) return ['type' => 'app'] + $res;
        $all = self::all();

        // exact host rows first (fixed hosts / apex fleet domains)
        if (isset($all[$h])) {
            $r = $all[$h];
            if (!$r['live']) return ['type' => 'inactive', 'kind' => $r['kind']] + $res;
            if ($r['kind'] === 'fixed') {
                // fixed host without its own '/' route: same as before (subdomain funnel on its parent)
                $dot = strpos($h, '.');
                return ['type' => 'fixed', 'kind' => 'fixed', 'sso' => false,
                        'label' => substr($h, 0, $dot), 'fleet' => substr($h, $dot + 1)] + $res;
            }
            return ['type' => 'apex', 'fleet' => $h, 'kind' => $r['kind'], 'sso' => $r['sso']] + $res;
        }

        // one label on top of a fleet domain (longest suffix wins)
        $best = null;
        foreach ($all as $d => $r) {
            if (str_ends_with($h, '.' . $d) && ($best === null || strlen($d) > strlen($best))) $best = $d;
        }
        if ($best === null) return $res;
        $label = substr($h, 0, -strlen($best) - 1);
        if ($label === '' || str_contains($label, '.')) return $res;   // a.b.fleet -> unknown
        $r = $all[$best];
        if (!$r['live'] || $r['kind'] === 'fixed') return ['type' => 'inactive', 'fleet' => $best, 'label' => $label] + $res;
        return ['type' => 'sub', 'fleet' => $best, 'label' => $label, 'kind' => $r['kind'], 'sso' => $r['sso']] + $res;
    }

    /**
     * May the flagship hand a signed-in session to this host?
     * Only live, sso-enabled fleet domains (apex) and their wall subdomains
     * (a Domain row whose funnel is in 'ai' mode). Page-mode (user HTML) hosts,
     * fixed hosts, unknown hosts and the flagship itself are always refused.
     */
    public static function handoffAllowed(string $host): bool
    {
        if (!config('fleet.sso_enabled', true)) return false;
        $r = self::resolve($host);
        if (!$r['sso']) return false;
        if ($r['type'] === 'apex') return true;
        if ($r['type'] !== 'sub') return false;
        try {
            $label = self::utf8($r['label']);
            $fleet = self::utf8($r['fleet']);
            $q = Domain::query()->where('domainselected', $fleet);
            DB::getDriverName() === 'mysql' ? $q->whereRaw('BINARY `domain` = ?', [$label]) : $q->where('domain', $label);
            $dom = $q->first();
            if (!$dom || !$dom->funnelid) return false;
            $mode = DB::table('ez_funnels')->where('id', $dom->funnelid)->value('displaymode');
            return $mode === 'ai';
        } catch (\Throwable $e) {
            return false;
        }
    }
}
