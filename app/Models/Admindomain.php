<?php

namespace App\Models;

use App\Support\HostRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Admindomain extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain',
        'days',
        'status',
        'kind',          // EZ-REL-20261016-3: fleet | fixed
        'sso_enabled',   // may ez.wiki hand a sign-in to this domain's hosts
        'activated_at',
    ];

	protected $attributes = [
    'days' => ''
	];

    protected $casts = [
        'sso_enabled' => 'boolean',
        'activated_at' => 'datetime',
    ];

    /**
     * Activation hook: a domain becomes routable the moment its row is saved with a
     * live status (admin UI or SQL through Eloquent) — no routes/web.php edit, no deploy.
     * Raw SQL edits need `php artisan cache:forget fleet.registry.v1` (or wait 5 min).
     */
    protected static function booted(): void
    {
        static::saving(function (Admindomain $d) {
            $live = in_array((string) $d->status, HostRegistry::LIVE, true);
            if ($live && empty($d->activated_at) && \Illuminate\Support\Facades\Schema::hasColumn('admindomains', 'activated_at')) {
                $d->activated_at = now();
            }
        });
        static::saved(fn () => HostRegistry::flush());
        static::deleted(fn () => HostRegistry::flush());
    }
}
