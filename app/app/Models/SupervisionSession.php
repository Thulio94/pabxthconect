<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisionSession extends Model
{
    protected $fillable = ['supervisor_user_id', 'target_extension_id', 'call_record_id', 'mode', 'status', 'ip_address', 'user_agent', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function supervisor(): BelongsTo { return $this->belongsTo(User::class, 'supervisor_user_id'); }
    public function targetExtension(): BelongsTo { return $this->belongsTo(Extension::class, 'target_extension_id'); }
    public function callRecord(): BelongsTo { return $this->belongsTo(CallRecord::class); }
}
