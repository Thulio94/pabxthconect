<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\AmiEventProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AmiEventProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_asterisk_events_create_complete_call_history_and_recording_metadata(): void
    {
        Storage::fake('pbx_recordings');
        $tenant = Tenant::create(['name' => 'Empresa PBX', 'slug' => 'empresa-pbx', 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'segredo', 'status' => 'active']);
        $trunk = SipTrunk::create(['name' => 'TECH', 'auth_mode' => 'ip_tech', 'host' => '192.0.2.10', 'tech_prefix' => '8033']);
        $processor = app(AmiEventProcessor::class);
        $uniqueId = '1720000000.12';

        $processor->process(['Event' => 'Newchannel', 'Channel' => "PJSIP/{$extension->sip_username}-00000001", 'Uniqueid' => $uniqueId, 'Linkedid' => $uniqueId, 'Exten' => '551736214392']);
        $processor->process(['Event' => 'DialBegin', 'Channel' => "PJSIP/{$extension->sip_username}-00000001", 'Uniqueid' => $uniqueId, 'DestChannel' => "PJSIP/trunk-{$trunk->id}-00000002"]);
        $processor->process(['Event' => 'BridgeEnter', 'Channel' => "PJSIP/{$extension->sip_username}-00000001", 'Uniqueid' => $uniqueId]);
        Storage::disk('pbx_recordings')->put("tenant-{$tenant->id}/{$uniqueId}.wav", str_repeat('a', 100));
        $processor->process(['Event' => 'Hangup', 'Uniqueid' => $uniqueId, 'Cause-txt' => 'Normal Clearing']);

        $this->assertDatabaseHas('call_records', ['asterisk_uniqueid' => $uniqueId, 'extension_id' => $extension->id, 'sip_trunk_id' => $trunk->id, 'status' => 'completed']);
        $this->assertDatabaseHas('recordings', ['path' => "tenant-{$tenant->id}/{$uniqueId}.wav"]);
        $this->assertNotNull($tenant->fresh()->extensions()->first()->calls()->first()->recording->available_at);
    }

    public function test_trunk_leg_hangup_finishes_call_using_linked_id(): void
    {
        Storage::fake('pbx_recordings');
        $tenant = Tenant::create(['name' => 'Empresa Linkedid', 'slug' => 'empresa-linkedid', 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'segredo', 'status' => 'active']);
        $processor = app(AmiEventProcessor::class);
        $linkedId = '1723480000.10';

        $processor->process(['Event' => 'Newchannel', 'Channel' => "PJSIP/{$extension->sip_username}-00000010", 'Uniqueid' => $linkedId, 'Linkedid' => $linkedId, 'Exten' => '5511999990000']);
        $processor->process(['Event' => 'BridgeEnter', 'Channel' => "PJSIP/{$extension->sip_username}-00000010", 'Uniqueid' => $linkedId]);
        Storage::disk('pbx_recordings')->put("tenant-{$tenant->id}/{$linkedId}.wav", str_repeat('a', 100));
        $processor->process(['Event' => 'Hangup', 'Uniqueid' => '1723480000.11', 'Linkedid' => $linkedId, 'Cause-txt' => 'Normal Clearing']);

        $call = CallRecord::where('asterisk_linkedid', $linkedId)->firstOrFail();
        $this->assertSame('completed', $call->status);
        $this->assertNotNull($call->ended_at);
        $this->assertNotNull($call->recording?->available_at);
    }

    public function test_ami_replaces_unavailable_browser_placeholder_and_finalizes_on_mixmonitor_stop(): void
    {
        Storage::fake('pbx_recordings');
        $tenant = Tenant::create(['name' => 'Empresa Browser', 'slug' => 'empresa-browser', 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'segredo', 'status' => 'active']);
        $call = CallRecord::create([
            'tenant_id' => $tenant->id, 'extension_id' => $extension->id,
            'asterisk_uniqueid' => 'web-placeholder', 'asterisk_linkedid' => 'web-placeholder',
            'direction' => 'outbound', 'from_number' => '999', 'to_number' => '5511999990000',
            'status' => 'dialing', 'started_at' => now(),
        ]);
        Recording::create(['call_record_id' => $call->id, 'storage_disk' => 'pbx_recordings', 'path' => "tenant-{$tenant->id}/browser-{$call->id}.webm"]);

        $uniqueId = '1723480000.99';
        $processor = app(AmiEventProcessor::class);
        $processor->process(['Event' => 'Newchannel', 'Channel' => "PJSIP/{$extension->sip_username}-00000099", 'Uniqueid' => $uniqueId, 'Linkedid' => $uniqueId, 'Exten' => '5511999990000']);
        $this->assertSame("tenant-{$tenant->id}/{$uniqueId}.wav", $call->fresh()->recording->path);

        $processor->process(['Event' => 'BridgeEnter', 'Channel' => "PJSIP/{$extension->sip_username}-00000099", 'Uniqueid' => $uniqueId]);
        Storage::disk('pbx_recordings')->put("tenant-{$tenant->id}/{$uniqueId}.wav", str_repeat('a', 100));
        $processor->process(['Event' => 'MixMonitorStop', 'Uniqueid' => $uniqueId, 'Linkedid' => $uniqueId]);

        $recording = $call->fresh()->recording;
        $this->assertNotNull($recording->available_at);
        $this->assertSame('audio/wav', $recording->mime_type);
        $this->assertSame(100, $recording->size_bytes);
    }

    public function test_ami_matches_browser_call_when_number_changes_from_national_to_e164(): void
    {
        Storage::fake('pbx_recordings');
        $tenant = Tenant::create(['name' => 'Empresa E164', 'slug' => 'empresa-e164', 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'segredo', 'status' => 'active']);
        $call = CallRecord::create([
            'tenant_id' => $tenant->id, 'extension_id' => $extension->id,
            'asterisk_uniqueid' => 'web-e164', 'asterisk_linkedid' => 'web-e164',
            'direction' => 'outbound', 'from_number' => '999', 'to_number' => '81996342657',
            'status' => 'dialing', 'started_at' => now(),
        ]);

        app(AmiEventProcessor::class)->process([
            'Event' => 'Newchannel', 'Channel' => "PJSIP/{$extension->sip_username}-00000101",
            'Uniqueid' => '1723480001.01', 'Linkedid' => '1723480001.01', 'Exten' => '5581996342657',
        ]);

        $this->assertDatabaseCount('call_records', 1);
        $this->assertSame('1723480001.01', $call->fresh()->asterisk_uniqueid);
    }

    public function test_unanswered_call_discards_empty_wav_and_keeps_human_result(): void
    {
        Storage::fake('pbx_recordings');
        $tenant = Tenant::create(['name' => 'Empresa Sem Resposta', 'slug' => 'sem-resposta', 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'segredo', 'status' => 'active']);
        $uniqueId = '1723480002.01';
        $path = "tenant-{$tenant->id}/{$uniqueId}.wav";
        $processor = app(AmiEventProcessor::class);

        $processor->process(['Event' => 'Newchannel', 'Channel' => "PJSIP/{$extension->sip_username}-00000102", 'Uniqueid' => $uniqueId, 'Linkedid' => $uniqueId, 'Exten' => '5581999990000']);
        Storage::disk('pbx_recordings')->put($path, str_repeat('a', 44));
        $processor->process(['Event' => 'Hangup', 'Uniqueid' => $uniqueId, 'Cause' => 18, 'Cause-txt' => 'No user responding']);

        $call = CallRecord::where('asterisk_uniqueid', $uniqueId)->firstOrFail();
        $this->assertSame('no_answer', $call->status);
        $this->assertSame(0, $call->duration_seconds);
        $this->assertNotNull($call->recording?->deleted_at);
        Storage::disk('pbx_recordings')->assertMissing($path);
    }
}
