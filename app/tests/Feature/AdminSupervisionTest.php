<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\ExtensionPresence;
use App\Models\PauseReason;
use App\Models\OperatorActivityLog;
use App\Models\OperatorPauseSession;
use App\Models\OperatorSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSupervisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_lists_tenant_agents_and_creates_audited_supervision(): void
    {
        $tenant = Tenant::create(['name' => 'Operação A', 'slug' => 'operacao-a', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'superadmin', 'must_change_password' => false]);
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Agente Um']);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'Abc12345', 'status' => 'active']);
        ExtensionPresence::create(['extension_id' => $extension->id, 'state' => 'available', 'state_since' => now(), 'heartbeat_at' => now()]);
        $call = CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'to_number' => '81999999999', 'status' => 'answered', 'started_at' => now()->subMinute(), 'answered_at' => now()->subSeconds(50)]);

        $this->actingAs($admin)->getJson('/administracao/acompanhamento/agentes?tenant_id='.$tenant->id)
            ->assertOk()->assertJsonPath('agents.0.state', 'talking')->assertJsonPath('agents.0.call.id', $call->id);

        $this->actingAs($admin)->postJson('/administracao/acompanhamento/ramais/'.$extension->id, ['mode' => 'whisper'])
            ->assertOk()->assertJsonPath('dial_number', '*82'.$extension->id);
        $this->assertDatabaseHas('supervision_sessions', ['supervisor_user_id' => $admin->id, 'target_extension_id' => $extension->id, 'call_record_id' => $call->id, 'mode' => 'whisper']);
    }

    public function test_pauses_are_scoped_to_company_and_agent_can_change_presence(): void
    {
        $tenant = Tenant::create(['name' => 'Operação A', 'slug' => 'operacao-a', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'superadmin', 'must_change_password' => false]);
        $agent = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'Abc12345', 'status' => 'active']);

        $this->actingAs($admin)->post('/administracao/pausas', ['tenant_id' => $tenant->id, 'name' => 'Banheiro', 'color' => '#f4b000', 'max_minutes' => 10])->assertRedirect()->assertSessionHasNoErrors();
        $pause = PauseReason::firstOrFail();
        $this->actingAs($admin)->get('/administracao')->assertOk()->assertSee('Configurar pausas')->assertSee('Banheiro');
        $session = ['sip_agent' => ['user_id' => $agent->id, 'tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'extension' => '999']];

        $this->actingAs($agent)->withSession($session)->postJson('/telefone/pausa', ['pause_reason_id' => $pause->id])->assertOk();
        $this->assertDatabaseHas('extension_presences', ['extension_id' => $extension->id, 'pause_reason_id' => $pause->id, 'state' => 'paused']);
        $this->deleteJson('/telefone/pausa')->assertOk();
        $this->assertDatabaseHas('extension_presences', ['extension_id' => $extension->id, 'pause_reason_id' => null, 'state' => 'available']);
    }

    public function test_agent_cannot_use_a_pause_from_another_company(): void
    {
        $tenant = Tenant::create(['name' => 'Operação A', 'slug' => 'operacao-a', 'status' => 'active']);
        $other = Tenant::create(['name' => 'Operação B', 'slug' => 'operacao-b', 'status' => 'active']);
        $agent = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'Abc12345', 'status' => 'active']);
        $foreignPause = PauseReason::create(['tenant_id' => $other->id, 'name' => 'Feedback', 'color' => '#7154e8']);

        $this->actingAs($agent)->withSession(['sip_agent' => ['user_id' => $agent->id, 'tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'extension' => '999']])
            ->postJson('/telefone/pausa', ['pause_reason_id' => $foreignPause->id])->assertUnprocessable();
    }

    public function test_company_admin_sees_only_own_company_and_daily_operator_metrics(): void
    {
        $this->travelTo(now()->setTime(15, 0));
        $tenant = Tenant::create(['name' => 'Operação A', 'slug' => 'operacao-a', 'status' => 'active']);
        $other = Tenant::create(['name' => 'Operação B', 'slug' => 'operacao-b', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin', 'must_change_password' => false]);
        Extension::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'number' => 1000, 'sip_username' => 't1-e1000', 'sip_secret' => 'Abc12345', 'status' => 'active']);
        $agent = User::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Agente Métricas']);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'Abc12345', 'status' => 'active']);
        $foreign = User::factory()->create(['tenant_id' => $other->id]);
        $foreignExtension = Extension::create(['tenant_id' => $other->id, 'user_id' => $foreign->id, 'number' => 999, 'sip_username' => 't2-e999', 'sip_secret' => 'Abc12345', 'status' => 'active']);
        ExtensionPresence::create(['extension_id' => $extension->id, 'state' => 'available', 'state_since' => now()->subHour(), 'heartbeat_at' => now()]);
        OperatorSession::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'extension_id' => $extension->id, 'logged_in_at' => now()->subHour(), 'last_seen_at' => now()]);
        OperatorPauseSession::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'extension_id' => $extension->id, 'pause_name' => 'Almoço', 'started_at' => now()->subMinutes(15), 'ended_at' => now()->subMinutes(5)]);
        OperatorActivityLog::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'extension_id' => $extension->id, 'action' => 'pause_ended', 'description' => 'Encerrou a pausa Almoço.', 'occurred_at' => now()->subMinutes(5)]);
        CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'to_number' => '81999999999', 'status' => 'completed', 'started_at' => now()->subMinutes(8), 'answered_at' => now()->subMinutes(7), 'ended_at' => now()->subMinutes(3), 'duration_seconds' => 300]);

        $this->actingAs($admin)->getJson('/administracao/acompanhamento/ramais/'.$extension->id.'/dia')
            ->assertOk()->assertJsonPath('summary.logged_seconds', 3600)->assertJsonPath('summary.calls', 1)
            ->assertJsonPath('summary.talk_seconds', 240)->assertJsonPath('summary.pause_seconds', 600)
            ->assertJsonPath('pause_breakdown.0.name', 'Almoço')->assertJsonPath('timeline.0.action', 'pause_ended');

        $this->actingAs($admin)->getJson('/administracao/acompanhamento/agentes?tenant_id='.$other->id)->assertForbidden();
        $this->actingAs($admin)->getJson('/administracao/acompanhamento/ramais/'.$foreignExtension->id.'/dia')->assertForbidden();
        $this->actingAs($admin)->get('/administracao')->assertForbidden();
    }
}
