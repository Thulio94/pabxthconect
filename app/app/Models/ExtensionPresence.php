<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExtensionPresence extends Model
{
    protected $fillable = ['extension_id', 'pause_reason_id', 'state', 'state_since', 'heartbeat_at'];

    protected function casts(): array
    {
        return ['state_since' => 'datetime', 'heartbeat_at' => 'datetime'];
    }

    public function extension(): BelongsTo { return $this->belongsTo(Extension::class); }
    public function pauseReason(): BelongsTo { return $this->belongsTo(PauseReason::class); }
}
