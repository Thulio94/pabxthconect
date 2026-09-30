<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\ExtensionPresence;
use App\Models\PauseReason;
use App\Models\PhoneLicenseLease;
use App\Models\Recording;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\CallStateReconciler;
use App\Services\Pbx\PbxConfigGenerator;
use App\Services\PhoneLicenseManager;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupervisorRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    public function test_supervisor_login_lands_in_its_read_only_workspace_and_password_change_returns_there(): void
    {
        $tenant = $this->tenant('Equipe Um');
        $supervisor = User::factory()->create([
            'tenant_id' => $tenant->id,
            'username' => 'supervisora@equipe.test',
            'email' => 'supervisora@equipe.test',
            'password' => Hash::make('Temporaria#2026'),
            'role' => 'supervisor',
            'must_change_password' => true,
        ]);

        $this->get('/administracao/entrar')->assertOk()->assertSee('Acesso administrativo');
        $this->post('/administracao/entrar', [
            'username' => $supervisor->email,
            'password' => 'Temporaria#2026',
        ])->assertRedirect('/administracao/primeiro-acesso');
        $this->assertAuthenticatedAs($supervisor);

        $this->put('/administracao/primeiro-acesso', [
            'password' => 'NovaSenhaSegura#2026',
            'password_confirmation' => 'NovaSenhaSegura#2026',
        ])->assertRedirect('/supervisor');

        $this->post('/administracao/sair')->assertRedirect('/administracao/entrar');
        $this->post('/administracao/entrar', [
            'username' => $supervisor->email,
            'password' => 'NovaSenhaSegura#2026',
        ])->assertRedirect('/supervisor');
    }

    public function test_supervisor_sees_full_live_statuses_only_for_its_company(): void
    {
        $tenant = $this->tenant('Empresa do supervisor');
        $otherTenant = $this->tenant('Outra empresa');
        $supervisor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'supervisor', 'must_change_password' => false]);
        $talkingAgent = $this->agent($tenant, 'Agente Falando', 999);
        $callingAgent = $this->agent($tenant, 'Agente Chamando', 1000);
        $pausedAgent = $this->agent($tenant, 'Agente em pausa', 1001);
        $availableAgent = $this->agent($tenant, 'Agente Disponível', 1002);
        $offlineAgent = $this->agent($tenant, 'Agente Offline', 1003);
        $foreignAgent = $this->agent($otherTenant, 'Agente Estrangeiro', 999);
        $this->markOnline($talkingAgent);
        $this->markOnline($callingAgent);
        $this->markOnline($pausedAgent);
        $this->markOnline($availableAgent);
        $this->markOnline($foreignAgent);
        $pause = PauseReason::create(['tenant_id' => $tenant->id, 'name' => 'Almoço', 'color' => '#e89c00', 'is_active' => true]);
        $pausedAgent->pbxExtension->presence()->update(['state' => 'paused', 'pause_reason_id' => $pause->id]);
        CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $talkingAgent->pbxExtension->id, 'to_number' => '5581999999999', 'status' => 'answered', 'started_at' => now()->subMinute(), 'answered_at' => now()->subSeconds(50)]);
        CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $callingAgent->pbxExtension->id, 'to_number' => '5581888888888', 'status' => 'ringing', 'started_at' => now()->subSeconds(10)]);
        $this->mock(CallStateReconciler::class)->shouldReceive('reconcile')->once();

        $response = $this->actingAs($supervisor)->getJson('/supervisor/agentes?tenant_id='.$otherTenant->id)->assertOk();

        $response->assertJsonCount(5, 'agents')
            ->assertJsonPath('online', 4)
            ->assertJsonPath('offline', 1)
            ->assertJsonFragment(['name' => 'Agente Falando', 'state' => 'talking', 'status_label' => 'Falando'])
            ->assertJsonFragment(['name' => 'Agente Chamando', 'state' => 'calling', 'status_label' => 'Chamando'])
            ->assertJsonFragment(['name' => 'Agente em pausa', 'state' => 'paused', 'status_label' => 'Almoço'])
            ->assertJsonFragment(['name' => 'Agente Disponível', 'state' => 'available', 'status_label' => 'Disponível'])
            ->assertJsonFragment(['name' => 'Agente Offline', 'state' => 'offline', 'status_label' => 'Offline'])
            ->assertJsonMissing(['name' => 'Agente Estrangeiro']);

        $talkingPayload = collect($response->json('agents'))->firstWhere('name', 'Agente Falando');
        $this->assertArrayNotHasKey('call', $talkingPayload);
        $this->assertArrayNotHasKey('can_force_logout', $talkingPayload);
    }

    public function test_supervisor_has_no_phone_or_telephony_actions_and_does_not_consume_a_license(): void
    {
        $tenant = $this->tenant('Empresa Supervisora');
        $supervisor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'supervisor', 'must_change_password' => false]);
        $agent = $this->agent($tenant, 'Operadora', 999);

        $this->actingAs($supervisor)->get('/supervisor')
            ->assertOk()->assertViewIs('admin.supervision')->assertSee('Acompanhamento de agentes')
            ->assertSee('data-state-counter="talking"', false)->assertSee('data-state-counter="calling"', false)
            ->assertSee('data-state-counter="available"', false)->assertSee('data-state-counter="paused"', false)
            ->assertSee('data-state-counter="offline"', false)->assertSee('id="supervisionAgents"', false)
            ->assertDontSee('Ações')->assertDontSee('Ouvir')->assertDontSee('Sussurrar')->assertDontSee('Entrar na ligação')
            ->assertDontSee('sip:', false)->assertDontSee('spyConsole', false);
        $this->get('/telefone')->assertRedirect('/supervisor');
        $this->postJson('/telefone/chamadas', ['direction' => 'outgoing', 'remote_number' => '5581999999999'])->assertForbidden();
        $this->postJson("/administracao/acompanhamento/ramais/{$agent->pbxExtension->id}", ['mode' => 'listen'])->assertForbidden();
        $this->get('/administracao')->assertForbidden();

        $this->assertFalse(app(PhoneLicenseManager::class)->requiresLicense($supervisor));
        $this->assertDatabaseCount('phone_license_leases', 0);
    }

    public function test_supervisor_recordings_are_limited_to_answered_agent_calls_in_the_same_tenant(): void
    {
        Storage::fake('local');
        $tenant = $this->tenant('Minha Empresa');
        $otherTenant = $this->tenant('Empresa Alheia');
        $supervisor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'supervisor', 'must_change_password' => false]);
        $ownAgent = $this->agent($tenant, 'Agente da Casa', 999);
        $foreignAgent = $this->agent($otherTenant, 'Agente de Fora', 999);
        $ownRecording = $this->recordingFor($tenant, $ownAgent, 'own.wav');
        $foreignRecording = $this->recordingFor($otherTenant, $foreignAgent, 'foreign.wav');

        $this->actingAs($supervisor)->get('/supervisor/gravacoes')
            ->assertOk()->assertSee('Agente da Casa')->assertDontSee('Agente de Fora')
            ->assertDontSee('tenant_id', false);
        $this->get("/supervisor/gravacoes/{$ownRecording->id}/ouvir")->assertOk();
        $this->get("/supervisor/gravacoes/{$foreignRecording->id}/ouvir")->assertForbidden();
    }

    public function test_company_administrator_can_create_agents_and_supervisors_only_for_its_own_company(): void
    {
        $tenant = $this->tenant('Empresa Admin');
        $otherTenant = $this->tenant('Empresa Separada');
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'must_change_password' => false]);
        $this->mock(PbxConfigGenerator::class, fn ($mock) => $mock->shouldReceive('generate')->once());

        $this->actingAs($admin)->get('/administracao/equipe/usuarios')
            ->assertOk()->assertSee('Criar agente ou supervisor')
            ->assertSee('value="agent"', false)->assertSee('value="supervisor"', false)
            ->assertDontSee('tenant_admin', false)->assertDontSee('superadmin', false);

        $agentResponse = $this->postJson('/administracao/equipe/usuarios', [
            'name' => 'Agente Criado',
            'email' => ' AGENTE@EMPRESA.TEST ',
            'role' => 'agent',
            'tenant_id' => $otherTenant->id,
        ])->assertCreated()->assertJsonPath('credentials.0.role', 'agent')
            ->assertJsonPath('credentials.0.extension', '999');
        $this->assertDatabaseHas('users', ['email' => 'agente@empresa.test', 'tenant_id' => $tenant->id, 'role' => 'agent']);

        $supervisorResponse = $this->postJson('/administracao/equipe/usuarios', [
            'name' => 'Supervisor Criado',
            'email' => 'supervisor@empresa.test',
            'role' => 'supervisor',
            'tenant_id' => $otherTenant->id,
        ])->assertCreated()->assertJsonPath('credentials.0.role', 'supervisor')
            ->assertJsonPath('credentials.0.extension', null)
            ->assertJsonPath('credentials.0.login', 'supervisor@empresa.test');

        $supervisor = User::query()->where('email', 'supervisor@empresa.test')->firstOrFail();
        $this->assertSame($tenant->id, $supervisor->tenant_id);
        $this->assertTrue($supervisor->must_change_password);
        $this->assertDatabaseMissing('extensions', ['user_id' => $supervisor->id]);
        $this->assertSame(1, Extension::query()->where('tenant_id', $tenant->id)->count());
        $this->assertNotEmpty($agentResponse->json('credentials.0.password'));
        $this->assertNotEmpty($supervisorResponse->json('credentials.0.password'));
    }

    public function test_company_administrator_cannot_create_privileged_roles_and_other_profiles_cannot_open_company_creation_screen(): void
    {
        $tenant = $this->tenant('Empresa Controlada');
        $otherTenant = $this->tenant('Outra Empresa');
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'must_change_password' => false]);
        $supervisor = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'supervisor', 'must_change_password' => false]);
        $superadmin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);

        foreach (['tenant_admin', 'superadmin'] as $role) {
            $this->actingAs($admin)->postJson('/administracao/equipe/usuarios', [
                'name' => 'Usuário privilegiado', 'email' => "{$role}@empresa.test", 'role' => $role,
            ])->assertUnprocessable()->assertJsonValidationErrors('role');
        }

        $this->actingAs($supervisor)->get('/administracao/equipe/usuarios')->assertForbidden();
        $this->actingAs($superadmin)->get('/administracao/equipe/usuarios')->assertForbidden();
        $this->actingAs($admin)->get('/supervisor')->assertForbidden();
        $this->assertDatabaseMissing('users', ['tenant_id' => $otherTenant->id, 'role' => 'supervisor']);
    }

    private function tenant(string $name): Tenant
    {
        return Tenant::create([
            'name' => $name,
            'slug' => str($name)->slug(),
            'status' => 'active',
            'extension_min' => 999,
            'extension_max' => 1000,
        ]);
    }

    private function agent(Tenant $tenant, string $name, int $number): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'agent', 'name' => $name]);
        Extension::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'number' => $number,
            'sip_username' => "t{$tenant->id}-e{$number}",
            'sip_secret' => 'Secret12',
            'status' => 'active',
        ]);

        return $user;
    }

    private function markOnline(User $user): void
    {
        $extension = $user->pbxExtension()->firstOrFail();
        PhoneLicenseLease::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'extension_id' => $extension->id,
            'session_key' => 'session-'.$user->id,
            'last_seen_at' => now(),
        ]);
        ExtensionPresence::create([
            'extension_id' => $extension->id,
            'state' => 'available',
            'state_since' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    private function recordingFor(Tenant $tenant, User $user, string $filename): Recording
    {
        $extension = $user->pbxExtension()->firstOrFail();
        $call = CallRecord::create([
            'tenant_id' => $tenant->id,
            'extension_id' => $extension->id,
            'direction' => 'outbound',
            'from_number' => '999',
            'to_number' => '5581999999999',
            'status' => 'answered',
            'started_at' => now()->subSeconds(15),
            'answered_at' => now()->subSeconds(14),
            'ended_at' => now(),
            'duration_seconds' => 14,
        ]);
        $path = "tenant-{$tenant->id}/{$filename}";
        Storage::disk('local')->put($path, str_repeat('a', 128));

        return Recording::create([
            'call_record_id' => $call->id,
            'storage_disk' => 'local',
            'path' => $path,
            'mime_type' => 'audio/wav',
            'size_bytes' => 128,
            'available_at' => now(),
        ]);
    }
}
