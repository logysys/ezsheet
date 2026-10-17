<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** EZ-LOCK-20261017 P2: a signed-in visitor asked the owner for the password of a private slug. */
class SlugPasswordRequest extends Model
{
    protected $table = 'slug_password_requests';
    protected $fillable = ['conversation_id', 'requester_user_id', 'requester_email', 'note', 'ip_address', 'status'];
    protected $hidden = ['ip_address'];
}
