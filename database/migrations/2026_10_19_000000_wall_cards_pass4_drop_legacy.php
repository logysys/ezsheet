<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Pass 4 (cleanup): drop the legacy wall_cards columns `raw`, `ok` and `type`.
 *
 *   raw  -> replaced by `content` (copied first where content is NULL/empty)
 *   ok   -> replaced by `status`  (status synced from ok first, see syncStatus())
 *   type -> replaced by role, derived from mother_id/anchor (nothing reads `type`
 *           after passes 2-3; see pass 4 README "type audit")
 *
 * Only run this after passes 1-3 are live and stable.
 * Idempotent: every step checks the current schema, so a second run is a no-op.
 * Before dropping, (id, raw, ok, type) are copied to wall_cards_pass4_backup.
 * down() re-adds the columns and backfills them from content/status/mother_id.
 * It does not read the backup table; see the README for an exact restore.
 */
return new class extends Migration
{
    private const BACKUP = 'wall_cards_pass4_backup';
    private const LEGACY = ['raw', 'ok', 'type'];

    public function up(): void
    {
        $present = array_values(array_filter(self::LEGACY, fn ($c) => Schema::hasColumn('wall_cards', $c)));
        if (!$present) {
            Log::info('[wall_cards pass4] legacy columns already gone, nothing to do');
            return;
        }

        // DDL outside the transaction (MySQL commits implicitly on CREATE TABLE).
        if (!Schema::hasTable(self::BACKUP)) {
            Schema::create(self::BACKUP, function (Blueprint $t) {
                $t->unsignedBigInteger('id')->primary();
                $t->longText('raw')->nullable();
                $t->boolean('ok')->nullable();
                $t->string('type', 50)->nullable();
                $t->timestamp('backed_up_at')->nullable();
            });
        }

        DB::transaction(function () use ($present) {
            // 1. raw -> content
            if (in_array('raw', $present, true)) {
                $n = DB::table('wall_cards')
                    ->where(fn ($q) => $q->whereNull('content')->orWhere('content', ''))
                    ->whereNotNull('raw')->where('raw', '<>', '')
                    ->update(['content' => DB::raw('raw')]);
                $diff = DB::table('wall_cards')->whereNotNull('raw')->where('raw', '<>', '')
                    ->whereColumn('raw', '<>', 'content')->count();
                Log::info("[wall_cards pass4] content filled from raw={$n}; rows where raw<>content (content kept)={$diff}");
            }

            // 2. ok -> status
            if (in_array('ok', $present, true)) {
                $this->syncStatus();
            }

            // 3. Backup of the legacy values (kept after the migration and after down()).
            //    The table itself is created above, outside the transaction.
            $cols = array_merge(['id'], $present);
            DB::table('wall_cards')->select($cols)->orderBy('id')->chunkById(500, function ($rows) {
                $now = now();
                DB::table(self::BACKUP)->insertOrIgnore($rows->map(fn ($r) => (array) $r + ['backed_up_at' => $now])->all());
            });
            Log::info('[wall_cards pass4] backup rows=' . DB::table(self::BACKUP)->count() . ' in ' . self::BACKUP);
        });

        // 4. Drop. Each column separately, so a re-run after a partial failure still works.
        foreach ($present as $col) {
            if (Schema::hasColumn('wall_cards', $col)) {
                Schema::table('wall_cards', fn (Blueprint $t) => $t->dropColumn($col));
            }
        }
        Log::info('[wall_cards pass4] dropped columns: ' . implode(',', $present));
    }

    public function down(): void
    {
        Schema::table('wall_cards', function (Blueprint $t) {
            if (!Schema::hasColumn('wall_cards', 'raw')) {
                $t->longText('raw')->nullable();
            }
            if (!Schema::hasColumn('wall_cards', 'type')) {
                $t->string('type', 50)->default('wiki');
            }
            if (!Schema::hasColumn('wall_cards', 'ok')) {
                $t->boolean('ok')->default(true);
            }
        });

        DB::table('wall_cards')->update(['raw' => DB::raw('content')]);
        DB::table('wall_cards')->update(['ok' => DB::raw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")]);
        DB::table('wall_cards')->update(['type' => DB::raw(
            "CASE WHEN mother_id IS NULL AND (anchor IS NULL OR anchor = '') THEN 'wiki' ELSE 'cowiki' END"
        )]);
        Log::info('[wall_cards pass4] down: raw/ok/type re-added and backfilled from content/status/mother_id');
    }

    /**
     * After passes 2-3 a card counts as pending when status = 'pending' OR ok = 0.
     * That effective state is now written to status:
     *  - mothers (no mother_id, no anchor) are always 'approved' (they have no pending state);
     *  - children with ok = 0 become 'pending';
     *  - NULL or unknown status becomes ok ? 'approved' : 'pending'.
     */
    private function syncStatus(): void
    {
        $isMother = fn ($q) => $q->whereNull('mother_id')->where(fn ($w) => $w->whereNull('anchor')->orWhere('anchor', ''));
        $isChild = fn ($q) => $q->where(fn ($w) => $w->whereNotNull('mother_id')->orWhere(fn ($v) => $v->whereNotNull('anchor')->where('anchor', '<>', '')));

        $mothers = DB::table('wall_cards')->where($isMother)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '<>', 'approved'))
            ->update(['status' => 'approved']);

        $unknown = DB::table('wall_cards')->where($isChild)
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['approved', 'pending']))
            ->update(['status' => DB::raw("CASE WHEN ok = 1 THEN 'approved' ELSE 'pending' END")]);

        $toPending = DB::table('wall_cards')->where($isChild)->where('ok', 0)->where('status', 'approved')
            ->update(['status' => 'pending', 'approved_at' => null]);

        $okPending = DB::table('wall_cards')->where($isChild)->where('ok', 1)->where('status', 'pending')->count();

        $approvedAt = DB::table('wall_cards')->where($isChild)->where('status', 'approved')->whereNull('approved_at')
            ->update(['approved_at' => DB::raw('updated_at')]);

        Log::info("[wall_cards pass4] status sync: mothers->approved={$mothers} child unknown/null fixed={$unknown} "
            . "child ok=0&approved->pending={$toPending} child ok=1&pending kept pending={$okPending} approved_at filled={$approvedAt}");
    }
};
