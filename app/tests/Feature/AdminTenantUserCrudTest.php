<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\PauseReason;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\PbxConfigGenerator;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTenantUserCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_superadmin_can_create_multiple_users_for_a_company_without_redirect_and_get_credentials_once(): void
    {
        $tenant = $this->tenant();
        $this->mock(PbxConfigGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->once());

        $response = $this->actingAs($this->superadmin())->postJson("/administracao/empresas/{$tenant->id}/usuarios", [
            'users' => [
                ['name' => 'Agente Leste', 'email' => ' LESTE@example.test ', 'role' => 'agent'],
                ['name' => 'Gestora Norte', 'email' => 'norte@example.test', 'role' => 'tenant_admin'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('provisioned', true)
            ->assertJsonCount(2, 'credentials')
            ->assertJsonPath('credentials.0.email', 'leste@example.test')
            ->assertJsonPath('credentials.0.extension', '999')
            ->assertJsonPath('credentials.1.extension', '1000')
            ->assertJsonPath('credentials.1.role', 'tenant_admin')
            ->assertJsonStructure(['users_html', 'message']);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('extensions', 2);
        $this->assertStringContainsString('Usuários cadastrados', $response->json('users_html'));
    }

    public function test_batch_validation_rejects_duplicate_email_before_creating_any_user(): void
    {
        $tenant = $this->tenant();

        $response = $this->actingAs($this->superadmin())->postJson("/administracao/empresas/{$tenant->id}/usuarios", [
            'users' => [
                ['name' => 'Agente Um', 'email' => 'same@example.test', 'role' => 'agent'],
                ['name' => 'Agente Dois', 'email' => 'SAME@example.test', 'role' => 'agent'],
            ],
        ]);
        $response->assertUnprocessable();
        $this->assertArrayHasKey('users.1.email', $response->json('errors'));

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('extensions', 0);
    }

    public function test_batch_rolls_back_every_user_if_the_company_runs_out_of_extensions(): void
    {
        $tenant = Tenant::create(['name' => 'Faixa curta', 'slug' => 'faixa-curta', 'status' => 'active', 'extension_min' => 999, 'extension_max' => 999]);

        $this->actingAs($this->superadmin())->postJson("/administracao/empresas/{$tenant->id}/usuarios", [
            'users' => [
                ['name' => 'Agente Um', 'email' => 'um@example.test', 'role' => 'agent'],
                ['name' => 'Agente Dois', 'email' => 'dois@example.test', 'role' => 'agent'],
            ],
        ])->assertUnprocessable()->assertJsonPath('message', 'Nenhum usuário foi criado. Confira os dados e a faixa de ramais disponíveis para esta empresa.');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('extensions', 0);
    }

    public function test_user_edit_and_delete_return_updated_company_list_as_json(): void
    {
        $tenant = $this->tenant();
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Antes', 'email' => 'antes@example.test']);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'number' => 999, 'sip_username' => "t{$tenant->id}-e999", 'sip_secret' => 'SIPabc12', 'status' => 'active']);
        $this->mock(PbxConfigGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->twice());
        $admin = $this->superadmin();

        $this->actingAs($admin)->putJson("/administracao/ramais/{$extension->id}", [
            'name' => 'Depois', 'email' => 'depois@example.test', 'number' => 999,
            'role' => 'agent', 'status' => 'active',
        ])->assertOk()->assertJsonPath('message', 'Usuário e ramal atualizados.')
            ->assertJsonPath('credentials', [])->assertJsonPath('users_html', fn ($html) => str_contains($html, 'depois@example.test'));

        $this->actingAs($admin)->deleteJson("/administracao/ramais/{$extension->id}")
            ->assertOk()->assertJsonPath('message', 'Usuário e ramal excluídos.')
            ->assertJsonPath('users_html', fn ($html) => ! str_contains($html, 'depois@example.test'));

        $this->assertDatabaseCount('extensions', 0);
    }

    public function test_pause_create_update_and_delete_can_refresh_the_modal_fragment_asynchronously(): void
    {
        $tenant = $this->tenant();
        $admin = $this->superadmin();

        $created = $this->actingAs($admin)->postJson('/administracao/pausas', [
            'tenant_id' => $tenant->id, 'name' => 'Água', 'color' => '#13a987', 'max_minutes' => 5,
        ])->assertCreated()->assertJsonPath('message', 'Pausa cadastrada para a empresa.');
        $this->assertStringContainsString('Água', $created->json('pause_html'));
        $pause = PauseReason::firstOrFail();

        $this->actingAs($admin)->putJson("/administracao/pausas/{$pause->id}", [
            'name' => 'Intervalo', 'color' => '#13a987', 'max_minutes' => 7, 'is_active' => '1',
        ])->assertOk()->assertJsonPath('message', 'Pausa atualizada.')
            ->assertJsonPath('pause_html', fn ($html) => str_contains($html, 'Intervalo'));

        $this->actingAs($admin)->deleteJson("/administracao/pausas/{$pause->id}")
            ->assertOk()->assertJsonPath('message', 'Pausa excluída.')
            ->assertJsonPath('pause_html', fn ($html) => str_contains($html, 'Nenhuma pausa cadastrada'));
    }

    public function test_user_creation_entry_point_is_inside_each_company_not_the_global_create_panel(): void
    {
        $tenant = $this->tenant();

        $this->actingAs($this->superadmin())->get('/administracao')
            ->assertOk()->assertSee("/administracao/empresas/{$tenant->id}/usuarios", false)
            ->assertSee('Criar usuários e ramais', false)
            ->assertSee('data-add-user', false)
            ->assertSee('data-export-credentials="csv"', false)
            ->assertSee('data-async-form="pauses"', false)
            ->assertSee('id="usuarios-ramais"', false)
            ->assertDontSee('Criar credencial', false);
    }

    private function tenant(): Tenant
    {
        return Tenant::create(['name' => 'Empresa de teste', 'slug' => 'empresa-de-teste', 'status' => 'active']);
    }

    private function superadmin(): User
    {
        return User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);
    }
}
