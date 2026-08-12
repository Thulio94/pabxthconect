<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SipTrunk extends Model
{
    protected $fillable = ['name', 'auth_mode', 'host', 'port', 'transport', 'username', 'password', 'tech_prefix', 'outbound_proxy', 'from_domain', 'from_user', 'codecs', 'is_active'];

    protected $hidden = ['username', 'password'];

    protected function casts(): array
    {
        return ['username' => 'encrypted', 'password' => 'encrypted', 'codecs' => 'array', 'is_active' => 'boolean'];
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_sip_trunks')->withPivot(['priority', 'is_active'])->withTimestamps();
    }

    public function calls(): HasMany
    {
        return $this->hasMany(CallRecord::class);
    }
}
