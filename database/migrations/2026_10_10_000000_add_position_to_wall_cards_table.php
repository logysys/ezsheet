<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('wall_cards')) {
            Schema::table('wall_cards', function (Blueprint $table) {
                if (!Schema::hasColumn('wall_cards', 'position')) {
                    $table->integer('position')->default(0)->after('status');
                    $table->index(['wall_id', 'position']);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('wall_cards') && Schema::hasColumn('wall_cards', 'position')) {
            Schema::table('wall_cards', function (Blueprint $table) {
                $table->dropColumn('position');
            });
        }
    }
};
