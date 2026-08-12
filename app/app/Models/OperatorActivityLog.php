<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorActivityLog extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'extension_id', 'action', 'description', 'metadata', 'occurred_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    public function extension(): BelongsTo { return $this->belongsTo(Extension::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
