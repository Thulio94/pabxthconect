<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhoneCallFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_uses_native_call_audio_without_console_or_browser_recording(): void
    {
        [$tenant, $extension] = $this->extension();

        $this->actingAs($extension->user)
            ->withSession(['sip_agent' => $this->agentSession($tenant, $extension)])
            ->get('/telefone')
            ->assertOk()
            ->assertDontSee('id="audioConsole"', false)
            ->assertDontSee('id="testMicrophoneButton"', false)
            ->assertSee('id="remoteAudio"', false);

        $javascript = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString('ensureAudioReadyForCall()', $javascript);
        $this->assertStringContainsString('return callMicrophoneStream;', $javascript);
        $this->assertStringContainsString('Asterisk MixMonitor', $javascript);
        $this->assertStringNotContainsString('await startRecording(session);', $javascript);
    }

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
        $call = CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'direction' => 'outbound', 'to_number' => '5511999999999', 'status' => 'completed', 'started_at' => now(), 'answered_at' => now(), 'ended_at' => now()]);
        Recording::create(['call_record_id' => $call->id, 'storage_disk' => 'pbx_recordings', 'path' => 'tenant-1/test.wav', 'size_bytes' => 100, 'available_at' => now()]);
        Storage::disk('pbx_recordings')->put('tenant-1/test.wav', str_repeat('a', 100));

        $this->actingAs($extension->user)->withSession(['sip_agent' => $this->agentSession($tenant, $extension)])
            ->get("/telefone/historico/{$call->id}/gravacao")->assertOk();

        $other = $this->extension('outra');
        $this->actingAs($other[1]->user)->withSession(['sip_agent' => $this->agentSession($other[0], $other[1])])
            ->get("/telefone/historico/{$call->id}/gravacao")->assertNotFound();
    }

    public function test_webphone_keeps_history_but_rejects_one_sided_browser_recording_uploads(): void
    {
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
            ->assertGone();
        $this->actingAs($extension->user)->withSession($session)
            ->patchJson("/telefone/chamadas/{$callId}", ['status' => 'completed', 'duration_seconds' => 12])->assertOk();

        $this->assertDatabaseHas('call_records', ['id' => $callId, 'extension_id' => $extension->id, 'status' => 'completed']);
        $this->assertDatabaseMissing('recordings', ['call_record_id' => $callId]);
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
        $this->assertDatabaseCount('recordings', 0);
    }

    public function test_agent_can_persist_sanitized_webrtc_media_diagnostics(): void
    {
        [$tenant, $extension] = $this->extension();
        $call = CallRecord::create([
            'tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'direction' => 'outbound',
            'to_number' => '5581999999999', 'status' => 'answered', 'started_at' => now(), 'answered_at' => now(),
        ]);

        $this->actingAs($extension->user)->withSession(['sip_agent' => $this->agentSession($tenant, $extension)])
            ->patchJson("/telefone/chamadas/{$call->id}", [
                'status' => 'answered',
                'media_diagnostics' => [
                    'state' => 'healthy',
                    'webrtc' => [
                        'inbound_packets' => 123, 'outbound_packets' => 456, 'inbound_bytes' => 1000,
                        'outbound_bytes' => 2000, 'packets_lost' => 2, 'jitter_ms' => 12,
                        'inbound_codec' => 'opus', 'outbound_codec' => 'PCMU',
                        'candidate_protocol' => 'udp', 'candidate_type' => 'relay', 'private_address' => 'must-not-save',
                    ],
                ],
            ])->assertOk();

        $diagnostics = $call->fresh()->media_diagnostics;
        $this->assertSame('healthy', data_get($diagnostics, 'browser.state'));
        $this->assertSame(123, data_get($diagnostics, 'browser.webrtc.inbound_packets'));
        $this->assertSame('relay', data_get($diagnostics, 'browser.webrtc.candidate_type'));
        $this->assertNull(data_get($diagnostics, 'browser.webrtc.private_address'));
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
