<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PbxNode extends Model
{
    protected $fillable = ['name', 'host', 'ami_host', 'ami_port', 'ami_username', 'ami_secret', 'is_active'];

    protected $hidden = ['ami_username', 'ami_secret'];

    protected function casts(): array
    {
        return ['ami_username' => 'encrypted', 'ami_secret' => 'encrypted', 'is_active' => 'boolean'];
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }
}
