<?php

namespace App\Http\Controllers;

use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Extension;
use App\Services\Pbx\ExtensionAllocator;
use App\Services\Pbx\AmiClient;
use App\Services\Pbx\PbxConfigGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SuperAdminController extends Controller
{
    public function index(): View
    {
        return view('admin.index', [
            'tenants' => Tenant::query()->with(['trunks', 'extensions.user'])->orderBy('name')->get(),
            'trunks' => SipTrunk::query()->withCount('tenants')->orderBy('name')->get(),
        ]);
    }

    public function storeTenant(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('tenants', 'slug')],
            // Kept optional only while the legacy prototype still exists.
            'internal_token' => ['nullable', 'string', 'min:20', 'max:4096'],
            'recording_retention_days' => ['nullable', 'integer', Rule::in([0, 30, 60, 90, 180, 365])],
        ]);

        Tenant::create([
            ...$data,
            'status' => 'active',
            'record_calls' => $request->boolean('record_calls', true),
        ]);
        $this->provision();

        return back()->with('status', 'Empresa criada. Vincule uma rota e depois crie os ramais.');
    }

    public function updateTenant(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:80', Rule::unique('tenants', 'slug')->ignore($tenant)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'internal_token' => ['nullable', 'string', 'min:20', 'max:4096'],
            'recording_retention_days' => ['nullable', 'integer', Rule::in([0, 30, 60, 90, 180, 365])],
        ]);

        if (blank($data['internal_token'] ?? null)) {
            unset($data['internal_token']);
        }

        $tenant->update([...$data, 'record_calls' => $request->boolean('record_calls', true)]);
        $this->provision();

        return back()->with('status', "Configuração de {$tenant->name} atualizada.");
    }

    public function storeTrunk(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sip_trunks', 'name')],
            'auth_mode' => ['required', Rule::in(['ip_tech', 'userpass'])],
            'host' => ['required', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'transport' => ['required', Rule::in(['udp', 'tcp', 'tls'])],
            'tech_prefix' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]+$/'],
            'username' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'outbound_proxy' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'from_domain' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'from_user' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
        ]);

        if ($data['auth_mode'] === 'ip_tech' && blank($data['tech_prefix'] ?? null)) {
            return back()->withErrors(['tech_prefix' => 'Informe o TECH para a rota autenticada por IP.'])->withInput();
        }
        if ($data['auth_mode'] === 'userpass' && (blank($data['username'] ?? null) || blank($data['password'] ?? null))) {
            return back()->withErrors(['username' => 'Informe usuário e senha para a rota autenticada.'])->withInput();
        }

        SipTrunk::create([...$data, 'is_active' => true, 'codecs' => ['ulaw', 'alaw']]);
        $this->provision();

        return back()->with('status', 'Rota SIP cadastrada e enviada ao PBX.');
    }

    public function attachTrunk(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'sip_trunk_id' => ['required', Rule::exists('sip_trunks', 'id')],
            'priority' => ['required', 'integer', 'between:1,999'],
        ]);
        $tenant->trunks()->syncWithoutDetaching([$data['sip_trunk_id'] => ['priority' => $data['priority'], 'is_active' => true]]);
        $this->provision();

        return back()->with('status', 'Rota vinculada à empresa.');
    }

    public function testTrunk(SipTrunk $trunk, AmiClient $ami): RedirectResponse
    {
        try {
            $this->provision();
            $ami->command('pjsip qualify trunk-'.$trunk->id);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['route_test' => 'O PBX não conseguiu enviar o teste pela rota. Revise host, porta, transporte e autorização de IP.']);
        }

        return back()->with('route_test', [
            'trunk_id' => $trunk->id,
            'message' => 'Teste SIP OPTIONS enviado pelo PBX. Aguarde alguns segundos: o status da rota será atualizado automaticamente.',
        ]);
    }

    public function updateTrunk(Request $request, SipTrunk $trunk): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('sip_trunks', 'name')->ignore($trunk)],
            'auth_mode' => ['required', Rule::in(['ip_tech', 'userpass'])],
            'host' => ['required', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'transport' => ['required', Rule::in(['udp', 'tcp', 'tls'])],
            'tech_prefix' => ['nullable', 'string', 'max:40', 'regex:/^[0-9]+$/'],
            'username' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'password' => ['nullable', 'string', 'max:255', 'not_regex:/[\r\n;#]/'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        if ($data['auth_mode'] === 'ip_tech' && blank($data['tech_prefix'] ?? null)) return back()->withErrors(['tech_prefix' => 'Informe o TECH para a rota autenticada por IP.']);
        if ($data['auth_mode'] === 'userpass' && blank($data['username'] ?? $trunk->username)) return back()->withErrors(['username' => 'Informe o usuário SIP.']);
        if (blank($data['password'] ?? null)) unset($data['password']);
        $trunk->update([...$data, 'is_active' => $request->boolean('is_active')]);
        $this->provision();
        return back()->with('status', 'Rota SIP atualizada.');
    }

    public function destroyTrunk(SipTrunk $trunk): RedirectResponse
    {
        $trunk->tenants()->detach();
        $trunk->delete();
        $this->provision();
        return back()->with('status', 'Rota SIP e seus vínculos foram excluídos.');
    }

    public function destroyTenant(Tenant $tenant): RedirectResponse
    {
        $tenant->delete();
        $this->provision();
        return back()->with('status', 'Empresa, usuários, ramais e vínculos foram excluídos.');
    }

    public function detachTrunk(Tenant $tenant, SipTrunk $trunk): RedirectResponse
    {
        $tenant->trunks()->detach($trunk->id);
        $this->provision();
        return back()->with('status', 'Rota desvinculada da empresa.');
    }

    public function updateExtension(Request $request, Extension $extension): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $allowedRoles = $extension->user?->isSuperAdmin() ? ['superadmin'] : ['agent', 'tenant_admin'];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($extension->user_id)],
            'role' => ['required', Rule::in($allowedRoles)],
            'number' => ['required', 'integer', 'between:999,10000', Rule::unique('extensions', 'number')->where(fn ($query) => $query->where('tenant_id', $extension->tenant_id))->ignore($extension)],
            'status' => ['required', Rule::in(['active', 'disabled'])],
            'rotate_secret' => ['nullable', 'boolean'],
        ]);
        $extension->user?->update(['name' => $data['name'], 'email' => $data['email'], 'role' => $data['role']]);
        $updates = ['number' => $data['number'], 'sip_username' => "t{$extension->tenant_id}-e{$data['number']}", 'status' => $data['status']];
        if ($request->boolean('rotate_secret')) {
            $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
            $updates['sip_secret'] = collect(range(1, 8))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
            $updates['secret_rotated_at'] = now();
            $extension->user?->forceFill(['password' => $updates['sip_secret']])->save();
        }
        $extension->update($updates);
        $this->provision();
        $response = back()->with('status', 'Usuário e ramal atualizados.');
        return isset($updates['sip_secret']) ? $response->with('new_extension_credentials', ['name' => $extension->user?->name, 'email' => $extension->user?->email, 'extension' => $extension->number, 'password' => $updates['sip_secret']]) : $response;
    }

    public function destroyExtension(Extension $extension): RedirectResponse
    {
        $user = $extension->user;
        if ($user?->isSuperAdmin()) {
            return back()->withErrors(['extension' => 'O ramal do superadmin não pode ser excluído.']);
        }
        $extension->delete();
        $user?->delete();
        $this->provision();
        return back()->with('status', 'Usuário e ramal excluídos.');
    }

    public function storeUser(Request $request, ExtensionAllocator $allocator): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'tenant_id' => ['required', Rule::exists('tenants', 'id')],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'role' => ['required', Rule::in(['agent', 'tenant_admin'])],
        ]);

        try {
            $user = User::create([
                'tenant_id' => $data['tenant_id'], 'name' => $data['name'], 'email' => $data['email'],
                'username' => 'u'.Str::lower(Str::random(20)), 'password' => Hash::make(Str::random(64)),
                'role' => $data['role'], 'must_change_password' => false,
            ]);
            $extension = $allocator->allocate($user);
            $extension->update(['status' => 'active', 'provisioned_at' => now()]);
            $this->provision();
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['user' => 'Não foi possível criar o usuário e o ramal.'])->withInput();
        }

        return back()->with('status', 'Usuário e ramal criados. A senha abaixo é definitiva até que o administrador gere outra.')
            ->with('new_extension_credentials', ['name' => $user->name, 'email' => $user->email, 'extension' => $extension->number, 'password' => $extension->sip_secret]);
    }

    private function provision(): void
    {
        app(PbxConfigGenerator::class)->generate();
        if (! app()->environment('testing')) {
            app(AmiClient::class)->command('module reload res_pjsip.so');
            app(AmiClient::class)->command('dialplan reload');
        }
    }
}
