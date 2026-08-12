<?php

use App\Models\Extension;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropUnique('extensions_number_unique');
            $table->unique(['tenant_id', 'number']);
        });

        $duplicates = DB::table('users')
            ->selectRaw('LOWER(email) AS normalized_email, COUNT(*) AS total')
            ->groupByRaw('LOWER(email)')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new \RuntimeException('Existem e-mails duplicados desconsiderando maiúsculas e minúsculas. Corrija-os antes de migrar.');
        }

        DB::table('users')->get(['id', 'email'])->each(function ($user): void {
            DB::table('users')->where('id', $user->id)->update(['email' => mb_strtolower(trim($user->email))]);
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (LOWER(email))');

        Extension::query()->with('user')->get()->each(function (Extension $extension): void {
            if ($extension->user && ! $extension->user->isSuperAdmin()) {
                $extension->user->forceFill([
                    'password' => $extension->sip_secret,
                    'must_change_password' => false,
                ])->save();
            }
        });

        User::query()->where('role', 'superadmin')->doesntHave('pbxExtension')->get()
            ->each(function (User $admin): void {
                $tenant = Tenant::query()->where('status', 'active')->whereHas('trunks')->orderBy('id')->first()
                    ?? Tenant::query()->where('status', 'active')->orderBy('id')->first()
                    ?? Tenant::create(['name' => 'Administração Thconect', 'slug' => 'administracao-thconect', 'status' => 'active']);
                $used = Extension::query()->where('tenant_id', $tenant->id)->pluck('number')->flip();
                $number = collect(range($tenant->extension_min, $tenant->extension_max))->first(fn (int $candidate) => ! $used->has($candidate));
                if ($number === null) {
                    throw new \RuntimeException('Não há ramal disponível para vincular o superadmin.');
                }
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
                $secret = collect(range(1, 8))->map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)])->implode('');
                $admin->forceFill(['tenant_id' => $tenant->id, 'must_change_password' => false])->save();
                Extension::create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $admin->id,
                    'number' => $number,
                    'sip_username' => "t{$tenant->id}-e{$number}",
                    'sip_secret' => $secret,
                    'status' => 'active',
                    'provisioned_at' => now(),
                    'secret_rotated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        $duplicates = DB::table('extensions')->select('number')->groupBy('number')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new \RuntimeException('Não é possível restaurar a unicidade global: existem números repetidos entre empresas.');
        }
        Schema::table('extensions', function (Blueprint $table) {
            $table->dropUnique('extensions_tenant_id_number_unique');
            $table->unique('number');
        });
    }
};
