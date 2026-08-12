<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Extension;
use App\Models\Recording;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_filter_and_list_available_recordings(): void
    {
        Storage::fake('pbx_recordings');
        $admin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);
        $tenant = Tenant::create(['name' => 'Empresa Gravada', 'slug' => 'gravada', 'status' => 'active']);
        $agent = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $agent->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'Abc12345', 'status' => 'active']);
        $call = CallRecord::create(['tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'to_number' => '5511999990001', 'status' => 'answered', 'started_at' => now(), 'duration_seconds' => 12]);
        $recording = Recording::create(['call_record_id' => $call->id, 'storage_disk' => 'pbx_recordings', 'path' => 'tenant-1/call.wav', 'mime_type' => 'audio/wav', 'available_at' => now()]);
        Storage::disk('pbx_recordings')->put($recording->path, 'audio-content');

        $this->actingAs($admin)->get('/administracao/gravacoes?phone=11999990001&status=answered')
            ->assertOk()->assertSee('Empresa Gravada')->assertSee('5511999990001')->assertSee('Atendida');
        $this->actingAs($admin)->get("/administracao/gravacoes/{$recording->id}/ouvir")
            ->assertOk()->assertHeader('content-type', 'audio/wav');
    }
}
