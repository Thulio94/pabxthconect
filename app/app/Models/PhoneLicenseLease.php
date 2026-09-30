<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneLicenseLease extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'extension_id', 'session_key', 'last_seen_at'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }
}
