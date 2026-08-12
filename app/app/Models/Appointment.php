<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    protected $fillable = [
        'tenant_id', 'user_id', 'extension_id', 'name', 'phone', 'scheduled_for',
        'status', 'snooze_count', 'notified_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'notified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function extension(): BelongsTo { return $this->belongsTo(Extension::class); }
}
