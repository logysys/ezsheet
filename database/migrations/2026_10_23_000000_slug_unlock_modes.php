<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * EZ-LOCK-20261017 P2: unlock modes for private slugs (Public / Private stays the base state).
 *  - ai_search_histories: unlock_email_enabled, unlock_password_enabled, unlock_password_hash, unlock_epoch
 *      (written on every row of the conversation, read from the root row, same as private_access_*)
 *  - private_access_logs: method, user_id, verified_at, last_viewed_at, views   (owner's viewer log)
 *  - login_tokens: subject (conversation_id for type = 'slug_unlock')            (reuses item 21 storage)
 *  - slug_password_requests: "Request password" messages to the owner
 * The old 4-digit access numbers are carried over as a HASHED password, switched OFF: the owner turns
 * password unlock on in the settings panel. The plaintext column is cleared in a later release.
 * Every step is guarded, so it is safe to re-run and safe on a partly migrated DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_search_histories', function (Blueprint $t) {
            if (!Schema::hasColumn('ai_search_histories', 'unlock_email_enabled')) $t->boolean('unlock_email_enabled')->default(false);
            if (!Schema::hasColumn('ai_search_histories', 'unlock_password_enabled')) $t->boolean('unlock_password_enabled')->default(false);
            if (!Schema::hasColumn('ai_search_histories', 'unlock_password_hash')) $t->string('unlock_password_hash', 255)->nullable();
            if (!Schema::hasColumn('ai_search_histories', 'unlock_epoch')) $t->unsignedInteger('unlock_epoch')->default(0);
        });

        Schema::table('private_access_logs', function (Blueprint $t) {
            if (!Schema::hasColumn('private_access_logs', 'method')) $t->string('method', 16)->nullable();
            if (!Schema::hasColumn('private_access_logs', 'user_id')) $t->unsignedBigInteger('user_id')->nullable();
            if (!Schema::hasColumn('private_access_logs', 'verified_at')) $t->timestamp('verified_at')->nullable();
            if (!Schema::hasColumn('private_access_logs', 'last_viewed_at')) $t->timestamp('last_viewed_at')->nullable();
            if (!Schema::hasColumn('private_access_logs', 'views')) $t->unsignedInteger('views')->default(0);
        });

        Schema::table('login_tokens', function (Blueprint $t) {
            if (!Schema::hasColumn('login_tokens', 'subject')) $t->string('subject', 64)->nullable()->index();
        });

        if (!Schema::hasTable('slug_password_requests')) {
            Schema::create('slug_password_requests', function (Blueprint $t) {
                $t->id();
                $t->string('conversation_id', 64)->index();
                $t->unsignedBigInteger('requester_user_id')->index();
                $t->string('requester_email', 191);
                $t->string('note', 500)->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->string('status', 16)->default('open');   // open | answered | dismissed
                $t->timestamps();
            });
        }

        // carry the old access numbers over as a hashed password (OFF until the owner turns it on)
        DB::table('ai_search_histories')
            ->whereNull('parent_id')
            ->whereNotNull('private_access_number')->where('private_access_number', '!=', '')
            ->whereNull('unlock_password_hash')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('ai_search_histories')->where('conversation_id', $row->conversation_id)
                        ->update(['unlock_password_hash' => Hash::make((string) $row->private_access_number)]);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('slug_password_requests');
        if (Schema::hasColumn('login_tokens', 'subject')) {
            Schema::table('login_tokens', function (Blueprint $t) {
                $t->dropIndex('login_tokens_subject_index');
                $t->dropColumn('subject');
            });
        }
        foreach (['method', 'user_id', 'verified_at', 'last_viewed_at', 'views'] as $c) {
            if (Schema::hasColumn('private_access_logs', $c)) {
                Schema::table('private_access_logs', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
        foreach (['unlock_email_enabled', 'unlock_password_enabled', 'unlock_password_hash', 'unlock_epoch'] as $c) {
            if (Schema::hasColumn('ai_search_histories', $c)) {
                Schema::table('ai_search_histories', fn (Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
