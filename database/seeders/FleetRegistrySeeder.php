<?php

namespace Database\Seeders;

use App\Support\HostRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EZ-REL-20261016-3 — registers every host that was hard-coded in routes/web.php.
 * Idempotent: existing rows keep their status and days (only empty kind is filled);
 * missing fleet domains are added as status 'Routed' (routed, NOT for sale in the
 * domain pickers, which only list 'Active'); fixed mini-app hosts as kind 'fixed',
 * sso_enabled 0. Run: php artisan db:seed --class=FleetRegistrySeeder --force
 */
class FleetRegistrySeeder extends Seeder
{
    public function run(): void
    {
        $existing = [];
        foreach (DB::table('admindomains')->get() as $r) {
            $existing[HostRegistry::ascii($r->domain)] = $r;
        }
        $now = now();
        $added = 0; $kept = 0;
        $todo = [];
        foreach (config('fleet.fallback_domains') as $d) $todo[$d] = 'fleet';
        foreach (config('fleet.fixed_hosts') as $d) $todo[$d] = 'fixed';
        foreach ($todo as $d => $kind) {
            $a = HostRegistry::ascii($d);
            if (isset($existing[$a])) {
                $upd = [];
                if ($kind === 'fixed') { $upd['kind'] = 'fixed'; $upd['sso_enabled'] = 0; }
                if (in_array($existing[$a]->status, HostRegistry::LIVE, true) && empty($existing[$a]->activated_at)) $upd['activated_at'] = $now;
                if ($upd) DB::table('admindomains')->where('id', $existing[$a]->id)->update($upd);
                $kept++;
                continue;
            }
            // admindomains.domain is stored the way admins typed it (pickers show it); use unicode
            DB::table('admindomains')->insert([
                'domain' => HostRegistry::utf8($a),
                'days' => 'all',
                'status' => 'Routed',
                'kind' => $kind,
                'sso_enabled' => $kind === 'fixed' ? 0 : 1,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $added++;
        }
        HostRegistry::flush();
        $this->command?->info("FleetRegistrySeeder: {$added} added (status Routed), {$kept} already present.");
    }
}
