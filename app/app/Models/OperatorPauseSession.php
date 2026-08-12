<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorPauseSession extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'extension_id', 'pause_reason_id', 'pause_name', 'started_at', 'ended_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function extension(): BelongsTo { return $this->belongsTo(Extension::class); }
    public function pauseReason(): BelongsTo { return $this->belongsTo(PauseReason::class); }
}
