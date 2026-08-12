<?php

namespace App\Services\Pbx;

use App\Models\Extension;
use App\Models\PbxNode;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ExtensionAllocator
{
    public function allocate(User $user, ?PbxNode $node = null): Extension
    {
        if (! $user->tenant_id) {
            throw new RuntimeException('O usuário precisa pertencer a uma empresa antes de receber um ramal.');
        }

        return DB::transaction(function () use ($user, $node) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($user->tenant_id);
            $used = Extension::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('number')
                ->flip();

            $number = null;
            for ($candidate = $tenant->extension_min; $candidate <= $tenant->extension_max; $candidate++) {
                if (! $used->has($candidate)) {
                    $number = $candidate;
                    break;
                }
            }

            if ($number === null) {
                throw new RuntimeException('Não há ramais disponíveis para esta empresa.');
            }

            $extension = Extension::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'pbx_node_id' => $node?->id,
                'number' => $number,
                'sip_username' => "t{$tenant->id}-e{$number}",
                'sip_secret' => $this->secret(),
                'status' => 'pending_provisioning',
                'secret_rotated_at' => now(),
            ]);
            $user->forceFill(['password' => $extension->sip_secret, 'must_change_password' => false])->save();

            return $extension;
        }, 3);
    }

    private function secret(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        return collect(range(1, 8))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }
}
