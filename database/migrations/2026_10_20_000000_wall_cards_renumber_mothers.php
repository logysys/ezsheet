<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * release-1013: renumber WiKi (mother) cards 0..n-1 per wall + zone where the
 * positions are not already exactly 0..n-1 (e.g. all 0 from Wall::createForEzSheet).
 * Keeps the current server order (position, then id). Children are not touched.
 * Idempotent: a second run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $walls = 0; $cards = 0; $bump = [];
        $groups = DB::table('wall_cards')
            ->whereNull('mother_id')
            ->where(fn ($q) => $q->whereNull('anchor')->orWhere('anchor', ''))
            ->select('wall_id', 'zone')->distinct()->get();

        foreach ($groups as $g) {
            DB::transaction(function () use ($g, &$walls, &$cards, &$bump) {
                $rows = DB::table('wall_cards')
                    ->where('wall_id', $g->wall_id)
                    ->where(fn ($q) => $g->zone === null ? $q->whereNull('zone') : $q->where('zone', $g->zone))
                    ->whereNull('mother_id')
                    ->where(fn ($q) => $q->whereNull('anchor')->orWhere('anchor', ''))
                    ->orderBy('position')->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'position']);
                $fixed = 0;
                foreach ($rows as $i => $row) {
                    if ((int) $row->position !== $i) {
                        DB::table('wall_cards')->where('id', $row->id)->update(['position' => $i]);
                        $fixed++;
                    }
                }
                if ($fixed) {
                    $walls++; $cards += $fixed;
                    $bump[$g->wall_id] = true;
                    Log::info('[wall_cards renumber] wall ' . $g->wall_id . ' zone ' . $g->zone . ': ' . $fixed . ' mothers renumbered');
                }
            });
        }
        // bump meta.rev once per changed wall so open walls resync
        foreach (array_keys($bump) as $wid) {
            DB::transaction(function () use ($wid) {
                $meta = json_decode((string) DB::table('walls')->where('id', $wid)->lockForUpdate()->value('meta'), true) ?: [];
                $meta['rev'] = (int) ($meta['rev'] ?? 0) + 1;
                DB::table('walls')->where('id', $wid)->update(['meta' => json_encode($meta)]);
            });
        }
        Log::info("[wall_cards renumber] done: {$walls} wall/zone groups, " . count($bump) . " walls, {$cards} cards");
    }

    public function down(): void
    {
        // Not reversible on purpose (old positions were all 0 / duplicates). Restore from the mysqldump if needed.
    }
};
