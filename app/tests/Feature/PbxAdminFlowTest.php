<?php

namespace Tests\Feature;

use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PbxAdminFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_creates_route_company_link_and_generated_extension(): void
    {
        config(['pbx.runtime_path' => storage_path('framework/testing/pbx-admin-runtime')]);
        File::deleteDirectory(config('pbx.runtime_path'));
        $admin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);

        $this->actingAs($admin)->post('/administracao/rotas', [
            'name' => 'Softswitch TECH', 'auth_mode' => 'ip_tech', 'host' => '198.51.100.10',
            'port' => 5060, 'transport' => 'udp', 'tech_prefix' => '8033',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $trunk = SipTrunk::firstOrFail();

        $this->actingAs($admin)->post('/administracao/empresas', [
            'name' => 'Empresa PBX', 'slug' => 'empresa-pbx', 'recording_retention_days' => 90, 'record_calls' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $tenant = Tenant::firstOrFail();

        $this->actingAs($admin)->post("/administracao/empresas/{$tenant->id}/rotas", [
            'sip_trunk_id' => $trunk->id, 'priority' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/administracao/usuarios', [
            'tenant_id' => $tenant->id, 'name' => 'Agente Principal', 'email' => 'agente@empresa.test', 'role' => 'agent',
        ])->assertRedirect()->assertSessionHas('new_extension_credentials');

        $this->assertDatabaseHas('extensions', ['tenant_id' => $tenant->id, 'number' => 999, 'status' => 'active']);
        $this->assertStringContainsString('Dial(PJSIP/8033${TH_DEST}@trunk-'.$trunk->id.',60,g)', File::get(config('pbx.runtime_path').'/extensions_tenants.conf'));
    }
}
