<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WallCard extends Model
{
    protected $fillable = [
        'wall_id', 'user_id', 'cid', 'me_hash', 'ip_hash', 'content', 'anchor', 'zone', 'status',
        'position', 'origin', 'mother_id', 'approved_at',
    ];

    // Pass 4: `raw`, `ok` and `type` columns are gone.
    //   content = card text, status = 'approved'|'pending', role = from mother_id/anchor.
    protected $attributes = [
        'status' => 'approved',
        'position' => 0,
    ];

    protected $casts = [
        'position' => 'integer',
        'mother_id' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function wall()
    {
        return $this->belongsTo(Wall::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Mother (WiKi) card of a Co-WiKi child. */
    public function mother()
    {
        return $this->belongsTo(self::class, 'mother_id');
    }

    /** Co-WiKi children, newest first (closest to mother). */
    public function children()
    {
        return $this->hasMany(self::class, 'mother_id')->orderByDesc('created_at')->orderByDesc('id');
    }

    /** Mother = no mother_id and no anchor. */
    public function isMother(): bool
    {
        return empty($this->attributes['mother_id'] ?? null)
            && (($this->attributes['anchor'] ?? null) === null || $this->attributes['anchor'] === '');
    }

    public function isChild(): bool
    {
        return !$this->isMother();
    }

    public function scopeMothers($q)
    {
        return $q->whereNull('mother_id')->where(fn ($w) => $w->whereNull('anchor')->orWhere('anchor', ''));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** 'mother' | 'child' (replaces the old `type` column: wiki | cowiki). */
    public function role(): string
    {
        return $this->isMother() ? 'mother' : 'child';
    }

    public function isApproved(): bool
    {
        return $this->status !== 'pending';
    }
}
