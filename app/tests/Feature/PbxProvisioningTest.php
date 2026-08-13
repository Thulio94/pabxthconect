<?php

namespace Tests\Feature;

use App\Models\SipTrunk;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\ExtensionAllocator;
use App\Services\Pbx\PbxConfigGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PbxProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_allocates_sequential_tenant_extensions_and_generates_tech_dialplan(): void
    {
        $runtime = storage_path('framework/testing/pbx-runtime');
        File::deleteDirectory($runtime);
        config(['pbx.runtime_path' => $runtime]);

        $tenant = Tenant::create(['name' => 'Empresa PBX', 'slug' => 'empresa-pbx', 'status' => 'active']);
        $trunk = SipTrunk::create([
            'name' => 'Rota TECH', 'auth_mode' => 'ip_tech', 'host' => '10.10.10.10',
            'tech_prefix' => '8033', 'is_active' => true,
        ]);
        $fallback = SipTrunk::create([
            'name' => 'Rota TECH reserva', 'auth_mode' => 'ip_tech', 'host' => '10.10.10.11',
            'tech_prefix' => '9044', 'is_active' => true,
        ]);
        $tenant->trunks()->attach($trunk, ['priority' => 1, 'is_active' => true]);
        $tenant->trunks()->attach($fallback, ['priority' => 2, 'is_active' => true]);

        $first = User::factory()->create(['tenant_id' => $tenant->id]);
        $second = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'tenant_admin']);
        $allocator = app(ExtensionAllocator::class);

        $firstExtension = $allocator->allocate($first);
        $secondExtension = $allocator->allocate($second);

        $otherTenant = Tenant::create(['name' => 'Outra Empresa', 'slug' => 'outra-empresa', 'status' => 'active']);
        $otherUser = User::factory()->create(['tenant_id' => $otherTenant->id]);
        $otherExtension = $allocator->allocate($otherUser);

        $this->assertSame(999, $firstExtension->number);
        $this->assertSame(1000, $secondExtension->number);
        $this->assertSame(999, $otherExtension->number);
        $this->assertSame("t{$tenant->id}-e999", $firstExtension->sip_username);

        app(PbxConfigGenerator::class)->generate();

        $endpoints = File::get($runtime.'/pjsip_endpoints.conf');
        $dialplan = File::get($runtime.'/extensions_tenants.conf');
        $this->assertStringContainsString("[{$firstExtension->sip_username}]", $endpoints);
        $this->assertStringContainsString('identify_by=username,auth_username', $endpoints);
        $this->assertStringContainsString('Set(TH_DEST=${FILTER(0-9,${EXTEN})})', $dialplan);
        $this->assertStringContainsString('Set(TH_DEST=55${TH_DEST})', $dialplan);
        $this->assertStringContainsString('Dial(PJSIP/8033${TH_DEST}@trunk-'.$trunk->id.',40,g)', $dialplan);
        $this->assertStringContainsString('Dial(PJSIP/9044${TH_DEST}@trunk-'.$fallback->id.',40,g)', $dialplan);
        $this->assertStringContainsString('StopMixMonitor()', $dialplan);
        $this->assertStringContainsString('System(rm -f "${RECORDING_ROOT}/${CALL_RECORDING_FILE}")', $dialplan);
        $this->assertLessThan(
            strpos($dialplan, 'Dial(PJSIP/9044${TH_DEST}@trunk-'.$fallback->id),
            strpos($dialplan, 'Dial(PJSIP/8033${TH_DEST}@trunk-'.$trunk->id),
        );
        $this->assertStringContainsString("Outbound blocked for tenant administrator {$secondExtension->id}", $dialplan);
        $this->assertStringContainsString("exten => *81{$firstExtension->id},1,NoOp(Listen {$firstExtension->id} by {$secondExtension->id})", $dialplan);
        $this->assertStringContainsString("ChanSpy(PJSIP,qbg(extension-{$firstExtension->id}))", $dialplan);
        $this->assertStringContainsString("ChanSpy(PJSIP,qbwg(extension-{$firstExtension->id}))", $dialplan);
        $this->assertStringContainsString("ChanSpy(PJSIP,qbBg(extension-{$firstExtension->id}))", $dialplan);
        $this->assertStringContainsString("Set(SPYGROUP=extension-{$firstExtension->id})", $dialplan);
        $this->assertStringNotContainsString("Set(__SPYGROUP=extension-{$firstExtension->id})", $dialplan);
        $this->assertStringNotContainsString('qEg(', $dialplan);
        $this->assertStringNotContainsString($firstExtension->sip_secret, $dialplan);
    }
}
