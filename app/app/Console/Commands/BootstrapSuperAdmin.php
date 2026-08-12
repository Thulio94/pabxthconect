<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\ExtensionAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BootstrapSuperAdmin extends Command
{
    protected $signature = 'app:bootstrap-superadmin
        {--login=superadmin : Login inicial do superadmin}
        {--email=superadmin@local.test : E-mail inicial do superadmin}
        {--password= : Senha temporária forte, preferencialmente fornecida por variável local}';

    protected $description = 'Cria o superadmin com acesso administrativo e ramal no telefone web';

    public function handle(): int
    {
        if (User::query()->where('role', 'superadmin')->exists()) {
            $this->error('Já existe um superadmin. Use o painel administrativo para gerir acessos.');

            return self::FAILURE;
        }

        $login = (string) $this->option('login');
        $email = Str::lower(trim((string) $this->option('email')));
        $password = (string) $this->option('password');

        if ($password === '' && $this->input->isInteractive()) {
            $password = (string) $this->secret('Defina a senha temporária');
        }

        if (mb_strlen($password) < 12) {
            $this->error('Informe uma senha temporária com pelo menos 12 caracteres.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('status', 'active')->orderBy('id')->first()
            ?? Tenant::create(['name' => 'Administração Thconect', 'slug' => 'administracao-thconect', 'status' => 'active']);
        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Superadmin',
            'username' => $login,
            'email' => $email,
            'password' => Hash::make($password),
            'role' => 'superadmin',
            'must_change_password' => false,
        ]);
        $extension = app(ExtensionAllocator::class)->allocate($user);
        $extension->update(['status' => 'active', 'provisioned_at' => now()]);
        $user->forceFill(['password' => $password])->save();

        $this->info("Superadmin '{$email}' criado com o ramal {$extension->number}. Use o e-mail e a senha informada em /entrar.");

        return self::SUCCESS;
    }
}
