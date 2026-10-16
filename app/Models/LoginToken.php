<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Item 21: one magic-link sign-in request (hashes only). */
class LoginToken extends Model
{
    protected $table = 'login_tokens';
    protected $fillable = ['email', 'token_hash', 'code_hash', 'attempts', 'return_to', 'expires_at', 'used_at', 'type', 'audience_host', 'user_id'];
    protected $casts = ['expires_at' => 'datetime', 'used_at' => 'datetime', 'attempts' => 'integer'];
    protected $hidden = ['token_hash', 'code_hash'];
}
