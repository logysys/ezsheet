<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EZ-REL-20261016-3
 *  - admindomains: kind, sso_enabled, activated_at (fleet host registry)
 *  - login_tokens: type, audience_host, user_id (60 s cross-domain handoff tokens)
 *  - users: session_epoch (logout everywhere)
 * Every step is guarded, so it is safe to re-run and safe on a partly migrated DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('admindomains')) {
            Schema::table('admindomains', function (Blueprint $t) {
                if (!Schema::hasColumn('admindomains', 'kind')) $t->string('kind', 16)->default('fleet');
                if (!Schema::hasColumn('admindomains', 'sso_enabled')) $t->boolean('sso_enabled')->default(true);
                if (!Schema::hasColumn('admindomains', 'activated_at')) $t->timestamp('activated_at')->nullable();
            });
        }
        Schema::table('login_tokens', function (Blueprint $t) {
            if (!Schema::hasColumn('login_tokens', 'type')) $t->string('type', 16)->default('login')->index();
            if (!Schema::hasColumn('login_tokens', 'audience_host')) $t->string('audience_host', 255)->nullable();
            if (!Schema::hasColumn('login_tokens', 'user_id')) $t->unsignedBigInteger('user_id')->nullable()->index();
        });
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'session_epoch')) $t->unsignedInteger('session_epoch')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'session_epoch')) $t->dropColumn('session_epoch');
        });
        foreach (['type', 'audience_host', 'user_id'] as $c) {
            if (Schema::hasColumn('login_tokens', $c)) {
                Schema::table('login_tokens', function (Blueprint $t) use ($c) {
                    if ($c !== 'audience_host') $t->dropIndex('login_tokens_' . $c . '_index');
                    $t->dropColumn($c);
                });
            }
        }
        if (Schema::hasTable('admindomains')) {
            foreach (['kind', 'sso_enabled', 'activated_at'] as $c) {
                if (Schema::hasColumn('admindomains', $c)) {
                    Schema::table('admindomains', fn (Blueprint $t) => $t->dropColumn($c));
                }
            }
        }
    }
};
