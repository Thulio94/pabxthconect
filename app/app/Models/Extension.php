<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Extension extends Model
{
    protected $fillable = ['tenant_id', 'user_id', 'pbx_node_id', 'number', 'sip_username', 'sip_secret', 'status', 'provisioned_at', 'secret_rotated_at'];

    protected $hidden = ['sip_secret'];

    protected function casts(): array
    {
        return ['sip_secret' => 'encrypted', 'provisioned_at' => 'datetime', 'secret_rotated_at' => 'datetime'];
    }

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function pbxNode(): BelongsTo { return $this->belongsTo(PbxNode::class); }
    public function calls(): HasMany { return $this->hasMany(CallRecord::class); }
    public function appointments(): HasMany { return $this->hasMany(Appointment::class); }
}
