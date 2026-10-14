<?php

namespace Tests\Feature;

use App\Models\Wall;
use App\Models\WallCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** release-1013: mothers get contiguous positions per zone (create path + data fix). */
class WallPositionsTest extends TestCase
{
    use RefreshDatabase;

    private const MIG = 'database/migrations/2026_10_20_000000_wall_cards_renumber_mothers.php';

    private function positions(Wall $w): array
    {
        return WallCard::where('wall_id', $w->id)->whereNull('mother_id')->orderBy('zone')->orderBy('position')->orderBy('id')
            ->get()->map(fn ($c) => $c->zone . $c->position . ':' . $c->content)->all();
    }

    public function test_create_for_ezsheet_numbers_mothers_per_zone_in_sheet_order(): void
    {
        $text = "A1\n━━━━\nB1\n━━━━\nB2\n━━━━\nB3";
        $wall = Wall::createForEzSheet($text, 'T', null);
        $cards = WallCard::where('wall_id', $wall->id)->orderBy('id')->get();
        $this->assertGreaterThan(1, $cards->count());
        foreach ($cards->groupBy('zone') as $zone => $g) {
            $this->assertSame(range(0, $g->count() - 1), $g->pluck('position')->map(fn ($p) => (int) $p)->all(), "zone $zone");
        }
        $this->assertSame(['wiki'], $cards->pluck('origin')->unique()->values()->all());
        // The API order equals sheet (creation) order.
        $api = $this->getJson('/api/wall/' . $wall->slug . '/cards')->assertOk()->json('cards');
        $this->assertSame($cards->sortBy(fn ($c) => sprintf('%d-%010d', ['a' => 0, 'b' => 1, 'c' => 2][$c->zone], $c->position))->pluck('cid')->values()->all(),
            array_values(array_column(array_filter($api, fn ($c) => $c['role'] === 'mother'), 'cid')));
    }

    public function test_renumber_migration_fixes_all_zero_and_duplicates_keeps_order_and_is_idempotent(): void
    {
        $w = Wall::create(['slug' => 'pos58', 'meta' => ['rev' => 3]]);
        $mk = fn ($cid, $zone, $pos, $extra = []) => WallCard::create($extra + ['wall_id' => $w->id, 'cid' => $cid, 'content' => $cid, 'zone' => $zone, 'status' => 'approved', 'position' => $pos]);
        $a = $mk('a1', 'a', 0);
        $mk('b1', 'b', 0); $mk('b2', 'b', 0); $mk('b3', 'b', 0); $mk('b4', 'b', 0);
        $mk('c1', 'c', 5);
        $child = $mk('ch', 'b', 0, ['mother_id' => $a->id, 'anchor' => 'a1']);
        $ok = Wall::create(['slug' => 'pos57', 'meta' => ['rev' => 9]]);
        WallCard::create(['wall_id' => $ok->id, 'cid' => 'k0', 'content' => 'k0', 'zone' => 'b', 'status' => 'approved', 'position' => 0]);
        WallCard::create(['wall_id' => $ok->id, 'cid' => 'k1', 'content' => 'k1', 'zone' => 'b', 'status' => 'approved', 'position' => 1]);

        $m = require base_path(self::MIG);
        $m->up();
        $this->assertSame(['a0:a1', 'b0:b1', 'b1:b2', 'b2:b3', 'b3:b4', 'c0:c1'], $this->positions($w));
        $this->assertSame(0, (int) $child->fresh()->position, 'children untouched');
        $this->assertSame(4, (int) $w->fresh()->meta['rev']);
        $this->assertSame(9, (int) $ok->fresh()->meta['rev'], 'clean wall untouched');

        $before = DB::table('wall_cards')->orderBy('id')->get()->toArray();
        $m->up();
        $this->assertEquals($before, DB::table('wall_cards')->orderBy('id')->get()->toArray());
        $this->assertSame(4, (int) $w->fresh()->meta['rev'], 'second run is a no-op');
    }
}
