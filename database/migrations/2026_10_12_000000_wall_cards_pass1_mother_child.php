<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pass 1 (data only): mother/child linkage for wall_cards.
 * Idempotent: every step checks current state, so running it twice is safe.
 */
return new class extends Migration
{
    private const UNIQUE = 'wall_cards_wall_id_cid_unique';
    private const FK = 'wall_cards_mother_id_foreign';
    private const BACKUP = 'wall_cards_dedupe_backup';

    public function up(): void
    {
        // 1. Columns (position/origin may already exist from hand edits on live).
        Schema::table('wall_cards', function (Blueprint $t) {
            if (!Schema::hasColumn('wall_cards', 'position')) {
                $t->integer('position')->default(0);
            }
            if (!Schema::hasColumn('wall_cards', 'origin')) {
                $t->string('origin', 50)->nullable();
            }
            if (!Schema::hasColumn('wall_cards', 'approved_at')) {
                $t->timestamp('approved_at')->nullable();
            }
            if (!Schema::hasColumn('wall_cards', 'mother_id')) {
                $t->unsignedBigInteger('mother_id')->nullable();
            }
        });

        // 2. Dedupe (wall_id, cid): keep newest row (max updated_at, then max id).
        //    Removed rows are copied to a backup table first, never just dropped.
        if (!$this->hasIndex(self::UNIQUE)) {
            $this->dedupe();
            Schema::table('wall_cards', function (Blueprint $t) {
                $t->unique(['wall_id', 'cid'], self::UNIQUE);
            });
        }

        // 3. Backfill mother_id from anchor (anchor = mother's cid; fall back to numeric id).
        $linked = 0; $orphans = 0;
        DB::table('wall_cards')->whereNotNull('anchor')->where('anchor', '<>', '')
            ->whereNull('mother_id')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$linked, &$orphans) {
                foreach ($rows as $r) {
                    $m = DB::table('wall_cards')->where('wall_id', $r->wall_id)
                        ->where('cid', $r->anchor)->where('id', '<>', $r->id)->value('id');
                    if (!$m && ctype_digit((string) $r->anchor)) {
                        $m = DB::table('wall_cards')->where('wall_id', $r->wall_id)
                            ->where('id', (int) $r->anchor)->where('id', '<>', $r->id)->value('id');
                    }
                    if ($m) {
                        DB::table('wall_cards')->where('id', $r->id)->update(['mother_id' => $m]);
                        $linked++;
                    } else {
                        $orphans++;
                    }
                }
            });
        Log::info("[wall_cards pass1] mother_id backfill: linked={$linked} orphans={$orphans}");

        // 4. FK with cascade (added after backfill so orphans stay NULL, not invalid).
        if (!$this->hasForeignKey(self::FK)) {
            Schema::table('wall_cards', function (Blueprint $t) {
                $t->foreign('mother_id', self::FK)->references('id')->on('wall_cards')->onDelete('cascade');
            });
        }

        // 5. Positions: mothers renumbered 0..n per wall (by position, then id); children = 0.
        DB::table('wall_cards')->whereNotNull('mother_id')->update(['position' => 0]);
        $walls = DB::table('wall_cards')->whereNull('mother_id')->distinct()->pluck('wall_id');
        foreach ($walls as $wallId) {
            $ids = DB::table('wall_cards')->where('wall_id', $wallId)->whereNull('mother_id')
                ->orderBy('position')->orderBy('id')->pluck('id');
            foreach ($ids as $i => $id) {
                DB::table('wall_cards')->where('id', $id)->where('position', '<>', $i)->update(['position' => $i]);
            }
        }

        // 6. approved_at for already-approved children.
        $n = DB::table('wall_cards')->whereNotNull('mother_id')->where('status', 'approved')
            ->whereNull('approved_at')->update(['approved_at' => DB::raw('updated_at')]);
        Log::info("[wall_cards pass1] approved_at backfilled={$n}");
    }

    public function down(): void
    {
        if ($this->hasForeignKey(self::FK)) {
            Schema::table('wall_cards', fn (Blueprint $t) => $t->dropForeign(self::FK));
        }
        if ($this->hasIndex(self::UNIQUE)) {
            Schema::table('wall_cards', fn (Blueprint $t) => $t->dropUnique(self::UNIQUE));
        }
        foreach (['mother_id', 'approved_at'] as $col) {
            if (Schema::hasColumn('wall_cards', $col)) {
                Schema::table('wall_cards', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        // position/origin are intentionally kept: they pre-date this migration on live
        // and the app reads them. Deduped rows remain in wall_cards_dedupe_backup.
    }

    private function dedupe(): void
    {
        $dups = DB::table('wall_cards')->select('wall_id', 'cid', DB::raw('COUNT(*) as c'))
            ->groupBy('wall_id', 'cid')->having('c', '>', 1)->get();
        Log::info('[wall_cards pass1] duplicate (wall_id,cid) groups=' . $dups->count());
        if ($dups->isEmpty()) {
            return;
        }
        if (!Schema::hasTable(self::BACKUP)) {
            DB::statement('CREATE TABLE ' . self::BACKUP . ' AS SELECT * FROM wall_cards WHERE 1=0');
        }
        $removed = 0;
        foreach ($dups as $d) {
            $rows = DB::table('wall_cards')->where('wall_id', $d->wall_id)->where('cid', $d->cid)
                ->orderByDesc('updated_at')->orderByDesc('id')->get();
            $keep = $rows->shift();
            $drop = $rows->pluck('id')->all();
            // Re-point anything referencing a dropped row to the kept row.
            DB::table('wall_cards')->whereIn('mother_id', $drop)->update(['mother_id' => $keep->id]);
            DB::statement('INSERT INTO ' . self::BACKUP . ' SELECT * FROM wall_cards WHERE id IN (' .
                implode(',', array_map('intval', $drop)) . ')');
            $removed += DB::table('wall_cards')->whereIn('id', $drop)->delete();
            Log::warning("[wall_cards pass1] dedupe wall={$d->wall_id} cid={$d->cid} kept={$keep->id} removed=" . implode(',', $drop));
        }
        Log::info("[wall_cards pass1] dedupe removed rows={$removed} (backup in " . self::BACKUP . ')');
    }

    private function hasIndex(string $name): bool
    {
        return collect(Schema::getIndexes('wall_cards'))->contains(fn ($i) => $i['name'] === $name);
    }

    private function hasForeignKey(string $name): bool
    {
        $fks = Schema::getForeignKeys('wall_cards');
        return collect($fks)->contains(fn ($f) => ($f['name'] ?? null) === $name
            || (in_array('mother_id', $f['columns'] ?? [], true)));
    }
};
