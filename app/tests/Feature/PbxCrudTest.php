<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PbxCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_edit_and_delete_route_company_and_extension(): void
    {
        $admin = User::factory()->create(['role' => 'superadmin', 'must_change_password' => false]);
        $tenant = Tenant::create(['name' => 'Empresa', 'slug' => 'empresa', 'status' => 'active']);
        $trunk = SipTrunk::create(['name' => 'Rota', 'auth_mode' => 'ip_tech', 'host' => '192.0.2.10', 'tech_prefix' => '8033']);
        $tenant->trunks()->attach($trunk, ['priority' => 1, 'is_active' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'agent@teste.local']);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'segredo', 'status' => 'active']);

        $this->actingAs($admin)->put("/administracao/rotas/{$trunk->id}", [
            'name' => 'Rota Atualizada', 'auth_mode' => 'ip_tech', 'host' => '192.0.2.20', 'port' => 5060, 'transport' => 'udp', 'tech_prefix' => '9000', 'is_active' => '1',
        ])->assertRedirect();
        $this->assertDatabaseHas('sip_trunks', ['id' => $trunk->id, 'name' => 'Rota Atualizada', 'tech_prefix' => '9000']);

        $this->actingAs($admin)->put("/administracao/ramais/{$extension->id}", [
            'name' => 'Agente Atualizado', 'email' => 'novo@teste.local', 'role' => 'tenant_admin', 'number' => 1000, 'status' => 'active', 'rotate_secret' => '1',
        ])->assertRedirect()->assertSessionHas('new_extension_credentials');
        $this->assertDatabaseHas('extensions', ['id' => $extension->id, 'number' => 1000]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'novo@teste.local']);

        $this->actingAs($admin)->delete("/administracao/empresas/{$tenant->id}/rotas/{$trunk->id}")->assertRedirect();
        $this->assertDatabaseMissing('tenant_sip_trunks', ['tenant_id' => $tenant->id, 'sip_trunk_id' => $trunk->id]);

        $this->actingAs($admin)->delete("/administracao/ramais/{$extension->id}")->assertRedirect();
        $this->assertDatabaseMissing('extensions', ['id' => $extension->id]);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);

        $this->actingAs($admin)->delete("/administracao/rotas/{$trunk->id}")->assertRedirect();
        $this->assertDatabaseMissing('sip_trunks', ['id' => $trunk->id]);
    }
}
