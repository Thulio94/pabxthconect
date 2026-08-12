<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id', 'name', 'username', 'email', 'password', 'role', 'extension',
        'must_change_password', 'failed_attempts', 'locked_until', 'password_changed_at',
    ];

    protected $hidden = ['password', 'remember_token'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'must_change_password' => 'boolean',
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'superadmin';
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === 'tenant_admin';
    }

    public function canManageOperation(): bool
    {
        return $this->isSuperAdmin() || $this->isTenantAdmin();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pbxExtension()
    {
        return $this->hasOne(Extension::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
}
