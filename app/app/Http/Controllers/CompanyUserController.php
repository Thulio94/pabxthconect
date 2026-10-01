<?php

namespace App\Http\Controllers;

use App\Models\Extension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OperatorActivityRecorder;
use App\Services\Pbx\AmiClient;
use App\Services\Pbx\ExtensionAllocator;
use App\Services\Pbx\PbxConfigGenerator;
use App\Services\PhoneLicenseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CompanyUserController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = (int) $request->user()->tenant_id;
        $tenant = Tenant::query()->findOrFail($tenantId);
        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['agent', 'supervisor'])
            ->with('pbxExtension')
            ->orderBy('name')
            ->get();

        return view('admin.company-users', compact('tenant', 'users'));
    }

    public function store(
        Request $request,
        ExtensionAllocator $allocator,
        PbxConfigGenerator $provisioner,
        AmiClient $ami,
    ): JsonResponse {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email'), Rule::unique('users', 'username')],
            'role' => ['required', Rule::in(['agent', 'supervisor'])],
        ]);

        $tenant = Tenant::query()->whereKey($request->user()->tenant_id)->where('status', 'active')->firstOrFail();
        $initialPassword = $data['role'] === 'supervisor' ? Str::random(32).'aA1!' : Str::random(64);

        try {
            [$user, $extension] = DB::transaction(function () use ($data, $tenant, $initialPassword, $allocator): array {
                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => trim($data['name']),
                    'username' => $data['email'],
                    'email' => $data['email'],
                    'password' => $initialPassword,
                    'role' => $data['role'],
                    'must_change_password' => $data['role'] === 'supervisor',
                ]);

                if (! $user->isAgent()) {
                    return [$user, null];
                }

                $extension = $allocator->allocate($user);
                $extension->update(['status' => 'active', 'provisioned_at' => now()]);

                return [$user, $extension];
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'O usuário não foi criado. Confira o e-mail e, para agentes, a faixa de ramais disponível.',
            ], 422);
        }

        $provisioned = true;
        $message = $user->isSupervisor()
            ? 'Supervisor criado. O acesso é somente pelo portal administrativo.'
            : 'Agente criado e ramal enviado ao PBX.';

        if ($extension) {
            try {
                $provisioner->generate();
                if (! app()->environment('testing')) {
                    $ami->command('core reload');
                }
            } catch (Throwable $exception) {
                report($exception);
                $provisioned = false;
                $message = 'O agente foi criado, mas o PBX não confirmou a aplicação do ramal. As credenciais estão disponíveis abaixo; revise a conexão do PBX antes de liberar o acesso.';
            }
        }

        return response()->json([
            'message' => $message,
            'provisioned' => $provisioned,
            'users_html' => $this->usersHtml((int) $tenant->id),
            'credentials' => [[
                'name' => $user->name,
                'email' => $user->email,
                'login' => $user->email,
                'extension' => $extension ? (string) $extension->number : null,
                'role' => $user->role,
                'password' => $extension?->sip_secret ?? $initialPassword,
            ]],
        ], $provisioned ? 201 : 202);
    }

    public function update(
        Request $request,
        User $user,
        ExtensionAllocator $allocator,
        PhoneLicenseManager $licenses,
        OperatorActivityRecorder $activity,
        PbxConfigGenerator $provisioner,
        AmiClient $ami,
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;
        $tenant = Tenant::query()->findOrFail($tenantId);
        $user = $this->companyUser($tenantId, (int) $user->id);
        $currentExtension = $user->pbxExtension;
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(['agent', 'supervisor'])],
            'number' => ['nullable', 'integer', "between:{$tenant->extension_min},{$tenant->extension_max}", Rule::unique('extensions', 'number')
                ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                ->ignore($currentExtension?->id)],
            'status' => ['nullable', Rule::in(['active', 'disabled'])],
            'reset_password' => ['nullable', 'boolean'],
        ]);

        if ($data['role'] === 'supervisor' && $data['email'] !== $user->username) {
            validator(['username' => $data['email']], [
                'username' => [Rule::unique('users', 'username')->ignore($user->id)],
            ])->validate();
        }

        $newPassword = null;
        $extensionChanged = false;
        $message = 'Usuário atualizado.';

        try {
            DB::transaction(function () use ($request, $data, $user, $tenantId, $allocator, $licenses, $activity, &$newPassword, &$extensionChanged): void {
                Tenant::query()->lockForUpdate()->findOrFail($tenantId);
                $user = User::query()->where('tenant_id', $tenantId)->whereKey($user->id)->lockForUpdate()->firstOrFail();
                $extension = Extension::query()->where('tenant_id', $tenantId)->where('user_id', $user->id)->lockForUpdate()->first();
                $wasAgent = $user->isAgent();
                $becomesAgent = $data['role'] === 'agent';
                $roleChanged = $wasAgent !== $becomesAgent;
                $status = $data['status'] ?? ($roleChanged && $becomesAgent ? 'active' : ($extension?->status ?? 'active'));
                $mustRevokeAccess = $extension && (($wasAgent && (! $becomesAgent || $status !== 'active')) || $request->boolean('reset_password'));

                if ($mustRevokeAccess) {
                    $this->revokePhoneAccess($request, $extension, $user, $licenses, $activity);
                }

                $updates = [
                    'name' => trim($data['name']),
                    'email' => $data['email'],
                    'role' => $data['role'],
                ];
                if (! $wasAgent && $becomesAgent) {
                    $updates['username'] = 'u'.Str::lower(Str::random(20));
                } elseif ($data['role'] === 'supervisor') {
                    $updates['username'] = $data['email'];
                }

                if ($roleChanged && ! $becomesAgent) {
                    $newPassword = Str::random(32).'aA1!';
                    $updates['password'] = $newPassword;
                    $updates['must_change_password'] = true;
                    $updates['password_changed_at'] = null;
                }

                $user->update($updates);

                if ($becomesAgent && ! $extension) {
                    $extension = $allocator->allocate($user);
                    $extension->update(['status' => 'active', 'provisioned_at' => now()]);
                    $newPassword = $extension->sip_secret;
                    $extensionChanged = true;
                } elseif ($extension && $roleChanged) {
                    $extension->update([
                        'status' => $becomesAgent ? $status : 'disabled',
                        'number' => $becomesAgent && filled($data['number']) ? (int) $data['number'] : $extension->number,
                        'sip_username' => "t{$tenantId}-e".($becomesAgent && filled($data['number']) ? (int) $data['number'] : $extension->number),
                    ]);
                    $extensionChanged = true;
                    if ($becomesAgent) {
                        $newPassword = $this->rotateAgentPassword($user, $extension);
                    }
                } elseif ($extension && $becomesAgent) {
                    $extensionUpdates = ['status' => $status];
                    if (filled($data['number']) && (int) $data['number'] !== (int) $extension->number) {
                        $extensionUpdates['number'] = (int) $data['number'];
                        $extensionUpdates['sip_username'] = "t{$tenantId}-e".(int) $data['number'];
                        $extensionChanged = true;
                    }
                    if ($status !== $extension->status) {
                        $extensionChanged = true;
                    }
                    if ($request->boolean('reset_password')) {
                        $extensionUpdates['sip_secret'] = $this->generateSipPassword();
                        $extensionUpdates['secret_rotated_at'] = now();
                        $newPassword = $extensionUpdates['sip_secret'];
                        $extensionChanged = true;
                    }
                    $extension->update($extensionUpdates);
                    if ($newPassword) {
                        $user->forceFill(['password' => $newPassword, 'must_change_password' => false, 'password_changed_at' => now()])->save();
                    }
                } elseif ($request->boolean('reset_password')) {
                    $newPassword = Str::random(32).'aA1!';
                    $user->forceFill(['password' => $newPassword, 'must_change_password' => true, 'password_changed_at' => null])->save();
                }
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Não foi possível atualizar o usuário. Confira os dados e tente novamente.'], 422);
        }

        if ($extensionChanged) {
            try {
                $provisioner->generate();
                if (! app()->environment('testing')) {
                    $ami->command('core reload');
                }
            } catch (Throwable $exception) {
                report($exception);
                $message = 'Os dados do usuário foram salvos, mas o PBX não confirmou a atualização do ramal. Revise a conexão do PBX antes de liberar o acesso.';
            }
        }

        $user->refresh()->load('pbxExtension');
        $credentials = $newPassword ? [[
            'name' => $user->name,
            'email' => $user->email,
            'login' => $user->email,
            'extension' => $user->pbxExtension ? (string) $user->pbxExtension->number : null,
            'role' => $user->role,
            'password' => $newPassword,
        ]] : [];

        return response()->json([
            'message' => $message,
            'users_html' => $this->usersHtml($tenantId),
            'credentials' => $credentials,
        ]);
    }

    public function destroy(
        Request $request,
        User $user,
        PhoneLicenseManager $licenses,
        OperatorActivityRecorder $activity,
        PbxConfigGenerator $provisioner,
        AmiClient $ami,
    ): JsonResponse {
        $tenantId = (int) $request->user()->tenant_id;
        $user = $this->companyUser($tenantId, (int) $user->id);
        $extension = $user->pbxExtension;

        try {
            DB::transaction(function () use ($request, $user, $tenantId, $extension, $licenses, $activity): void {
                Tenant::query()->lockForUpdate()->findOrFail($tenantId);
                if ($extension) {
                    $this->revokePhoneAccess($request, $extension, $user, $licenses, $activity);
                    $extension->delete();
                }
                $user->delete();
            }, 3);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Não foi possível excluir o usuário. Tente novamente ou contate o suporte.'], 422);
        }

        if ($extension) {
            try {
                $provisioner->generate();
                if (! app()->environment('testing')) {
                    $ami->command('core reload');
                }
            } catch (Throwable $exception) {
                report($exception);

                return response()->json([
                    'message' => 'Usuário excluído, mas o PBX não confirmou a remoção do ramal. Revise a conexão do PBX.',
                    'users_html' => $this->usersHtml($tenantId),
                ], 202);
            }
        }

        return response()->json([
            'message' => 'Usuário excluído da empresa. A licença, quando aplicável, foi liberada.',
            'users_html' => $this->usersHtml($tenantId),
        ]);
    }

    private function companyUser(int $tenantId, int $userId): User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['agent', 'supervisor'])
            ->with('pbxExtension')
            ->whereKey($userId)
            ->firstOrFail();
    }

    private function usersHtml(int $tenantId): string
    {
        $tenant = Tenant::query()->findOrFail($tenantId);
        $users = User::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('role', ['agent', 'supervisor'])
            ->with('pbxExtension')
            ->orderBy('name')
            ->get();

        return view('admin.partials.company-users-list', compact('tenant', 'users'))->render();
    }

    private function revokePhoneAccess(
        Request $request,
        Extension $extension,
        User $user,
        PhoneLicenseManager $licenses,
        OperatorActivityRecorder $activity,
    ): void {
        $lease = $licenses->releaseForExtension($extension);
        if ($lease?->session_key) {
            $request->session()->getHandler()->destroy($lease->session_key);
        }

        $activity->forceLogout($extension, $user, $request->user());
    }

    private function rotateAgentPassword(User $user, Extension $extension): string
    {
        $password = $this->generateSipPassword();
        $extension->update(['sip_secret' => $password, 'secret_rotated_at' => now()]);
        $user->forceFill(['password' => $password, 'must_change_password' => false, 'password_changed_at' => now()])->save();

        return $password;
    }

    private function generateSipPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

        return collect(range(1, 12))->map(fn (): string => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
    }
}
