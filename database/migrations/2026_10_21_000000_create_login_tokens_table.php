<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Item 21: magic-link sign-in. Only hashes are stored; rows are single-use and expire after 15 minutes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('email', 191)->index();
            $t->char('token_hash', 64)->unique();   // sha256 of the 32-byte link token
            $t->char('code_hash', 64);              // HMAC-SHA256 (app key) of email|6-digit code
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('return_to', 2000)->nullable();
            $t->timestamp('expires_at')->index();
            $t->timestamp('used_at')->nullable();   // set on use, on 5 wrong codes, or when a newer link is sent
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_tokens');
    }
};
