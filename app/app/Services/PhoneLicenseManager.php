<?php

namespace App\Services;

use App\Models\Extension;
use App\Models\PhoneLicenseLease;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PhoneLicenseManager
{
    public function requiresLicense(User $user): bool
    {
        return $user->role === 'agent';
    }

    public function acquire(User $user, Extension $extension, string $sessionKey): PhoneLicenseLease
    {
        return DB::transaction(function () use ($user, $extension, $sessionKey) {
            $extension = Extension::query()->lockForUpdate()->findOrFail($extension->id);
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($extension->tenant_id);

            if (! $this->requiresLicense($user) || $extension->user_id !== $user->id || $extension->status !== 'active' || $tenant->status !== 'active') {
                throw new PhoneLicenseException('Este usuário não possui acesso à tela de telefonia.');
            }

            if (PhoneLicenseLease::query()->where('extension_id', $extension->id)->exists()) {
                throw new PhoneLicenseException('Este agente já está conectado à tela de telefonia em outro computador.');
            }

            $inUse = PhoneLicenseLease::query()->where('tenant_id', $tenant->id)->count();
            if ($inUse >= $tenant->concurrent_agent_limit) {
                throw new PhoneLicenseException('Todas as licenças de telefonia desta empresa estão em uso. Procure o administrador ou tente novamente mais tarde.');
            }

            return PhoneLicenseLease::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'extension_id' => $extension->id,
                'session_key' => $sessionKey,
            ]);
        }, 3);
    }

    public function hasActiveLease(Extension $extension): bool
    {
        return PhoneLicenseLease::query()->where('extension_id', $extension->id)->exists();
    }

    public function hasLeaseForSession(User $user, Extension $extension, string $sessionKey): bool
    {
        return PhoneLicenseLease::query()
            ->where('tenant_id', $extension->tenant_id)
            ->where('user_id', $user->id)
            ->where('extension_id', $extension->id)
            ->where('session_key', $sessionKey)
            ->exists();
    }

    public function releaseForSession(Extension $extension, string $sessionKey): ?PhoneLicenseLease
    {
        return DB::transaction(function () use ($extension, $sessionKey) {
            Tenant::query()->lockForUpdate()->findOrFail($extension->tenant_id);
            $lease = PhoneLicenseLease::query()->where('extension_id', $extension->id)->where('session_key', $sessionKey)->first();
            $lease?->delete();

            return $lease;
        }, 3);
    }

    public function releaseForExtension(Extension $extension): ?PhoneLicenseLease
    {
        return DB::transaction(function () use ($extension) {
            Tenant::query()->lockForUpdate()->findOrFail($extension->tenant_id);
            $lease = PhoneLicenseLease::query()->where('extension_id', $extension->id)->first();
            $lease?->delete();

            return $lease;
        }, 3);
    }
}
