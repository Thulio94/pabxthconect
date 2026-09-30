<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\AmiClient;
use App\Services\Pbx\ExtensionAllocator;
use App\Services\Pbx\PbxConfigGenerator;
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
        $tenant = Tenant::query()->findOrFail($request->user()->tenant_id);

        return view('admin.company-users', compact('tenant'));
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
}
