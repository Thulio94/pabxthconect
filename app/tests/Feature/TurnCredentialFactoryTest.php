<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Pbx\TurnCredentialFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnCredentialFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_issues_short_lived_credentials_without_exposing_the_shared_secret(): void
    {
        config([
            'pbx.turn.host' => 'turn.example.test',
            'pbx.turn.auth_secret' => 'shared-secret-not-for-browser',
            'pbx.turn.ttl_seconds' => 900,
            'pbx.turn.tls_enabled' => true,
            'pbx.turn.port' => 3478,
            'pbx.turn.tls_port' => 5349,
        ]);
        $tenant = Tenant::create(['name' => 'Empresa TURN', 'slug' => 'empresa-turn', 'status' => 'active']);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => 't1-e999', 'sip_secret' => 'secret', 'status' => 'active']);

        $servers = app(TurnCredentialFactory::class)->forExtension($extension);

        $this->assertCount(1, $servers);
        $this->assertStringStartsWith('turn:', $servers[0]['urls'][0]);
        $this->assertContains('turns:turn.example.test:5349?transport=tcp', $servers[0]['urls']);
        $this->assertStringContainsString(':ext-'.$extension->id, $servers[0]['username']);
        $this->assertNotSame('shared-secret-not-for-browser', $servers[0]['credential']);
    }
}
