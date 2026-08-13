<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\AmiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmiClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_channels_are_resolved_by_global_sip_username_not_extension_number(): void
    {
        $tenant = Tenant::create(['name' => 'Empresa AMI', 'slug' => 'empresa-ami', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'number' => 999,
            'sip_username' => "t{$tenant->id}-e999",
            'sip_secret' => 'Abc12345',
            'status' => 'active',
        ]);

        $this->assertNotSame(999, $extension->id);
        $this->assertSame([$extension->id], app(AmiClient::class)->extensionIdsForSipUsernames([
            "t{$tenant->id}-e999",
            "t{$tenant->id}-e999",
        ]));
    }
}
