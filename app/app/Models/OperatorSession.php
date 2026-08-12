<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OperatorSession extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'extension_id', 'session_key', 'ip_address', 'logged_in_at', 'last_seen_at', 'logged_out_at'];

    protected function casts(): array
    {
        return ['logged_in_at' => 'datetime', 'last_seen_at' => 'datetime', 'logged_out_at' => 'datetime'];
    }

    public function extension(): BelongsTo { return $this->belongsTo(Extension::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
