<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'google_id',
        'linkedin_id',
        'linkedin_access_token',
        'provider_id',
        'reddit_token',
        'reddit_refresh_token',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
    
    public function ezFunnels()
    {
        return $this->hasMany(EzFunnel::class);
    }
    
    public function magicLinks()
    {
        return $this->hasMany(MagicLink::class);
    }

    public function createMagicLink($redirectUrl = null)
    {
        // EZ-REL-20261016-3: only the sha256 of the token is stored; the returned model
        // carries the raw token in memory (->token) for the e-mail link, as before.
        $raw = \Illuminate\Support\Str::random(64);
        $link = $this->magicLinks()->create([
            'token' => hash('sha256', $raw),
            'expires_at' => now()->addMinutes(min(60, (int) config('auth.magic_link.expire', 60))),
            'redirect_url' => \App\Http\Controllers\SsoHandoffController::safePath(
                is_string($redirectUrl) ? self::localPathOf($redirectUrl) : '/marketplace'),
        ]);
        $link->setAttribute('token', $raw);
        return $link;
    }
    
    /** Turns https://<this app host>/path?q into /path?q; anything else becomes '/'. */
    public static function localPathOf(string $url): string
    {
        if ($url === '' || $url[0] === '/') return $url === '' ? '/' : $url;
        $p = parse_url($url);
        $host = strtolower($p['host'] ?? '');
        $own = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($host === '' || ($host !== $own && $host !== strtolower((string) request()->getHost()))) return '/';
        return ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }

    public function sendMagicLinkNotification($url)
    {
        $this->notify(new \App\Notifications\MagicLinkNotification($url));
    }
    
    public function tokenTransactions()
    {
        return $this->hasMany(TokenTransaction::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function balance()
    {
        return $this->hasOne(UserBalance::class);
    }

    public function aiSetting()
    {
        return $this->hasOne(AIUserSetting::class);
    }
}