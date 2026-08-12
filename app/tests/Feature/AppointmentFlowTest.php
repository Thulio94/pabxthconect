<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Extension;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_schedule_and_receive_a_due_appointment(): void
    {
        [$user, $extension, $session] = $this->agent();
        $scheduled = now('America/Sao_Paulo')->addMinutes(30)->format('Y-m-d\TH:i');

        $response = $this->actingAs($user)->withSession(['sip_agent' => $session])
            ->postJson('/telefone/agenda', ['name' => 'Maria Silva', 'phone' => '(81) 99999-0000', 'scheduled_for' => $scheduled])
            ->assertCreated()->assertJsonPath('appointment.name', 'Maria Silva');

        $appointment = Appointment::findOrFail($response->json('appointment.id'));
        $this->assertSame('81999990000', $appointment->phone);
        $this->assertSame($extension->id, $appointment->extension_id);

        $appointment->update(['scheduled_for' => now()->subMinute()]);
        $this->actingAs($user)->withSession(['sip_agent' => $session])
            ->getJson('/telefone/agenda')->assertOk()
            ->assertJsonPath('appointments.0.is_due', true)
            ->assertJsonPath('appointments.0.name', 'Maria Silva');
    }

    public function test_agent_can_snooze_and_complete_only_its_own_appointment(): void
    {
        [$user, $extension, $session] = $this->agent();
        $appointment = Appointment::create([
            'tenant_id' => $extension->tenant_id, 'user_id' => $user->id, 'extension_id' => $extension->id,
            'name' => 'João', 'phone' => '81988887777', 'scheduled_for' => now()->subMinute(), 'status' => 'pending',
        ]);

        $this->actingAs($user)->withSession(['sip_agent' => $session])
            ->patchJson("/telefone/agenda/{$appointment->id}", ['action' => 'snooze', 'minutes' => 15])
            ->assertOk()->assertJsonPath('appointment.is_due', false);
        $this->assertSame(1, $appointment->fresh()->snooze_count);

        [$otherUser, , $otherSession] = $this->agent('outra');
        $this->actingAs($otherUser)->withSession(['sip_agent' => $otherSession])
            ->patchJson("/telefone/agenda/{$appointment->id}", ['action' => 'complete'])->assertNotFound();

        $this->actingAs($user)->withSession(['sip_agent' => $session])
            ->patchJson("/telefone/agenda/{$appointment->id}", ['action' => 'complete'])
            ->assertOk()->assertJsonPath('appointment.status', 'completed');
    }

    private function agent(string $suffix = ''): array
    {
        $tenant = Tenant::create(['name' => 'Empresa '.$suffix, 'slug' => 'agenda'.($suffix ?: '-teste'), 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $number = $suffix ? 1000 : 999;
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => $number, 'sip_username' => "t{$tenant->id}-e{$number}", 'sip_secret' => 'Senha123', 'status' => 'active']);
        $session = ['user_id' => $user->id, 'tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'extension' => (string) $number];
        return [$user, $extension, $session];
    }
}
