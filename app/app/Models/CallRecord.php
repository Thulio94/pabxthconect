<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CallRecord extends Model
{
    protected $fillable = ['tenant_id', 'extension_id', 'sip_trunk_id', 'asterisk_uniqueid', 'asterisk_linkedid', 'direction', 'from_number', 'to_number', 'dialed_uri', 'status', 'hangup_cause', 'started_at', 'answered_at', 'ended_at', 'duration_seconds'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function extension(): BelongsTo { return $this->belongsTo(Extension::class); }
    public function trunk(): BelongsTo { return $this->belongsTo(SipTrunk::class, 'sip_trunk_id'); }
    public function recording(): HasOne { return $this->hasOne(Recording::class); }
}
