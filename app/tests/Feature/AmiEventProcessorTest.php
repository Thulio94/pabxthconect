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
        Storage::disk('pbx_recordings')->put("tenant-{$tenant->id}/{$uniqueId}.wav", 'audio');
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
        Storage::disk('pbx_recordings')->put("tenant-{$tenant->id}/{$linkedId}.wav", 'audio');
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

        Storage::disk('pbx_recordings')->put("tenant-{$tenant->id}/{$uniqueId}.wav", 'audio-finalizado');
        $processor->process(['Event' => 'MixMonitorStop', 'Uniqueid' => $uniqueId, 'Linkedid' => $uniqueId]);

        $recording = $call->fresh()->recording;
        $this->assertNotNull($recording->available_at);
        $this->assertSame('audio/wav', $recording->mime_type);
        $this->assertSame(strlen('audio-finalizado'), $recording->size_bytes);
    }
}
