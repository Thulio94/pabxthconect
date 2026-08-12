<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhoneCall extends Model
{
    protected $fillable = [
        'tenant_id', 'extension', 'direction', 'remote_number', 'status',
        'started_at', 'answered_at', 'ended_at', 'duration_seconds',
        'recording_path', 'recording_mime', 'recording_size',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
