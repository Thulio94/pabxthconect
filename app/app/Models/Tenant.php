<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'status', 'theme', 'internal_token', 'record_calls',
        'recording_retention_days', 'extension_min', 'extension_max',
    ];

    protected $hidden = ['internal_token'];

    protected function casts(): array
    {
        return ['theme' => 'array', 'internal_token' => 'encrypted', 'record_calls' => 'boolean'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function trunks()
    {
        return $this->belongsToMany(SipTrunk::class, 'tenant_sip_trunks')->withPivot(['priority', 'is_active'])->withTimestamps();
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }
}
