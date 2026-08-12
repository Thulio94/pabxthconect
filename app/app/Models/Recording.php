<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recording extends Model
{
    protected $fillable = ['call_record_id', 'storage_disk', 'path', 'mime_type', 'size_bytes', 'available_at', 'delete_after', 'deleted_at'];

    protected function casts(): array
    {
        return ['available_at' => 'datetime', 'delete_after' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function call(): BelongsTo { return $this->belongsTo(CallRecord::class, 'call_record_id'); }
}
