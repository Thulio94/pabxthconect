<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\PhoneLicenseLease;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PhoneLicenseManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PhoneLicenseFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_login_reserves_a_license_and_logout_releases_it(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $extension = $this->agent('licencas', 1, 'agente@licencas.test');

        $this->post('/entrar', ['email' => $extension->user->email, 'password' => 'SenhaSIP#Segura'])
            ->assertRedirect('/telefone');
        $this->assertDatabaseHas('phone_license_leases', ['extension_id' => $extension->id]);

        $this->post('/sair')->assertRedirect('/entrar');
        $this->assertDatabaseMissing('phone_license_leases', ['extension_id' => $extension->id]);
    }

    public function test_capacity_and_second_login_are_rejected_without_creating_another_lease(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $first = $this->agent('capacidade', 1, 'primeiro@capacidade.test');
        $second = $this->agent('capacidade', 1, 'segundo@capacidade.test', $first->tenant);

        $this->post('/entrar', ['email' => $first->user->email, 'password' => 'SenhaSIP#Segura'])->assertRedirect('/telefone');
        $this->post('/entrar', ['email' => $first->user->email, 'password' => 'SenhaSIP#Segura'])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('phone_license_leases', 1);
        $this->assertTrue(app(PhoneLicenseManager::class)->hasActiveLease($first));
        $this->expectExceptionMessage('Todas as licenças de telefonia desta empresa estão em uso.');
        app(PhoneLicenseManager::class)->acquire($second->user, $second, 'segunda-sessao');
    }

    public function test_administrators_and_superadmins_do_not_consume_phone_licenses(): void
    {
        $manager = app(PhoneLicenseManager::class);
        $tenant = Tenant::create(['name' => 'Sem Consumo', 'slug' => 'sem-consumo', 'status' => 'active', 'concurrent_agent_limit' => 1]);
        $administrator = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin']);
        $superadmin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'superadmin']);

        $this->assertFalse($manager->requiresLicense($administrator));
        $this->assertFalse($manager->requiresLicense($superadmin));
    }

    public function test_superadmin_can_release_an_abandoned_license_from_company_settings(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $agent = $this->agent('liberacao', 1, 'agente@liberacao.test');
        $admin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);
        $lease = app(PhoneLicenseManager::class)->acquire($agent->user, $agent, 'sessao-abandonada');

        $this->actingAs($admin)->post("/administracao/empresas/{$agent->tenant_id}/licencas/{$lease->id}/deslogar")
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertDatabaseMissing('phone_license_leases', ['id' => $lease->id]);
    }

    public function test_company_limit_cannot_be_reduced_below_active_leases(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $agent = $this->agent('reducao', 1, 'agente@reducao.test');
        $admin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);
        app(PhoneLicenseManager::class)->acquire($agent->user, $agent, 'sessao-em-uso');

        $this->actingAs($admin)->put("/administracao/empresas/{$agent->tenant_id}", [
            'name' => $agent->tenant->name,
            'slug' => $agent->tenant->slug,
            'status' => 'active',
            'recording_retention_days' => 90,
            'concurrent_agent_limit' => 0,
        ])->assertSessionHasErrors('concurrent_agent_limit');
    }

    public function test_agent_phone_routes_require_a_matching_license_lease(): void
    {
        $agent = $this->agent('sem-licenca', 1, 'agente@sem-licenca.test');

        $this->actingAs($agent->user)->withSession(['sip_agent' => [
            'user_id' => $agent->user_id,
            'tenant_id' => $agent->tenant_id,
            'extension_id' => $agent->id,
            'extension' => (string) $agent->number,
        ]])->postJson('/telefone/presenca', ['state' => 'available'])
            ->assertUnauthorized()
            ->assertJsonPath('session_ended', true);
    }

    public function test_disabling_an_agent_revokes_its_license_and_phone_session(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $agent = $this->agent('desativacao', 1, 'agente@desativacao.test');
        $admin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);
        app(PhoneLicenseManager::class)->acquire($agent->user, $agent, 'sessao-ativa');

        $this->actingAs($admin)->put("/administracao/ramais/{$agent->id}", [
            'name' => $agent->user->name,
            'email' => $agent->user->email,
            'role' => 'agent',
            'number' => $agent->number,
            'status' => 'disabled',
        ])->assertRedirect();

        $this->assertDatabaseMissing('phone_license_leases', ['extension_id' => $agent->id]);
    }

    private function agent(string $slug, int $limit, string $email, ?Tenant $tenant = null): Extension
    {
        $tenant ??= Tenant::create(['name' => ucfirst($slug), 'slug' => $slug, 'status' => 'active', 'concurrent_agent_limit' => $limit]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => $email, 'password' => Hash::make('SenhaSIP#Segura'), 'role' => 'agent']);

        return Extension::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'number' => 999 + Extension::query()->where('tenant_id', $tenant->id)->count(),
            'sip_username' => "t{$tenant->id}-e".(999 + Extension::query()->where('tenant_id', $tenant->id)->count()),
            'sip_secret' => 'SenhaSIP#Segura',
            'status' => 'active',
        ]);
    }
}
