<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhoneCallFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_the_twenty_five_latest_real_pbx_calls(): void
    {
        [$tenant, $extension] = $this->extension();
        $operationDayStart = now(config('app.display_timezone'))->startOfDay()->utc();
        foreach (range(1, 30) as $index) {
            CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'direction' => 'outbound', 'to_number' => "551199990{$index}", 'status' => 'completed', 'started_at' => $operationDayStart->copy()->addMinutes($index), 'ended_at' => now()]);
        }
        CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'direction' => 'outbound', 'to_number' => '5511888888888', 'status' => 'completed', 'started_at' => now()->subDay()]);

        $response = $this->actingAs($extension->user)->withSession(['sip_agent' => $this->agentSession($tenant, $extension)])->get('/telefone');
        $response->assertOk();
        $history = collect($response->viewData('history'));
        $this->assertCount(25, $history);
        $this->assertTrue($history->every(fn (CallRecord $call) => $call->started_at->copy()->timezone(config('app.display_timezone'))->isToday()));
    }

    public function test_agent_can_only_play_its_own_pbx_recording(): void
    {
        Storage::fake('pbx_recordings');
        [$tenant, $extension] = $this->extension();
        $call = CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'direction' => 'outbound', 'to_number' => '5511999999999', 'status' => 'completed', 'started_at' => now(), 'ended_at' => now()]);
        Recording::create(['call_record_id' => $call->id, 'storage_disk' => 'pbx_recordings', 'path' => 'tenant-1/test.wav', 'available_at' => now()]);
        Storage::disk('pbx_recordings')->put('tenant-1/test.wav', 'audio-content');

        $this->actingAs($extension->user)->withSession(['sip_agent' => $this->agentSession($tenant, $extension)])
            ->get("/telefone/historico/{$call->id}/gravacao")->assertOk();

        $other = $this->extension('outra');
        $this->actingAs($other[1]->user)->withSession(['sip_agent' => $this->agentSession($other[0], $other[1])])
            ->get("/telefone/historico/{$call->id}/gravacao")->assertNotFound();
    }

    public function test_webphone_persists_call_history_and_browser_recording_when_ami_is_unavailable(): void
    {
        Storage::fake('pbx_recordings');
        [$tenant, $extension] = $this->extension();
        $session = ['sip_agent' => $this->agentSession($tenant, $extension)];

        $created = $this->actingAs($extension->user)->withSession($session)
            ->postJson('/telefone/chamadas', ['direction' => 'outgoing', 'remote_number' => '(81) 99999-0000'])
            ->assertCreated();
        $callId = $created->json('id');

        $this->actingAs($extension->user)->withSession($session)
            ->patchJson("/telefone/chamadas/{$callId}", ['status' => 'answered'])->assertOk();
        $this->actingAs($extension->user)->withSession($session)
            ->post("/telefone/chamadas/{$callId}/gravacao", ['recording' => UploadedFile::fake()->createWithContent('chamada.webm', 'audio-do-navegador')])
            ->assertOk()->assertJsonPath('has_recording', true);
        $this->actingAs($extension->user)->withSession($session)
            ->patchJson("/telefone/chamadas/{$callId}", ['status' => 'completed', 'duration_seconds' => 12])->assertOk();

        $this->assertDatabaseHas('call_records', ['id' => $callId, 'extension_id' => $extension->id, 'status' => 'completed']);
        $this->assertDatabaseHas('recordings', ['call_record_id' => $callId]);
        $this->actingAs($extension->user)->withSession($session)->get('/telefone/historico/'.$callId.'/gravacao')->assertOk();
    }

    public function test_browser_reuses_asterisk_call_created_first_with_e164_number(): void
    {
        [$tenant, $extension] = $this->extension();
        $call = CallRecord::create([
            'tenant_id' => $tenant->id, 'extension_id' => $extension->id,
            'asterisk_uniqueid' => '1723480002.01', 'asterisk_linkedid' => '1723480002.01',
            'direction' => 'outbound', 'from_number' => '999', 'to_number' => '5581996342657',
            'status' => 'dialing', 'started_at' => now(),
        ]);
        $session = ['sip_agent' => $this->agentSession($tenant, $extension)];

        $this->actingAs($extension->user)->withSession($session)->postJson('/telefone/chamadas', [
            'direction' => 'outgoing', 'remote_number' => '81996342657',
        ])->assertCreated()->assertJsonPath('id', $call->id);

        $this->assertDatabaseCount('call_records', 1);
        $this->assertDatabaseCount('recordings', 1);
    }

    private function extension(string $suffix = ''): array
    {
        $tenant = Tenant::create(['name' => 'Empresa '.$suffix, 'slug' => 'empresa'.($suffix ?: '-teste'), 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $number = $suffix === '' ? 999 : 1000;
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => $number, 'sip_username' => "t{$tenant->id}-e{$number}", 'sip_secret' => 'senha-teste', 'status' => 'active']);
        return [$tenant, $extension];
    }

    private function agentSession(Tenant $tenant, Extension $extension): array
    {
        return ['user_id' => $extension->user_id, 'tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'extension' => (string) $extension->number];
    }
}
