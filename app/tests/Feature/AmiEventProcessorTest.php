<?php

namespace Tests\Feature;

use App\Models\Extension;
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
}
