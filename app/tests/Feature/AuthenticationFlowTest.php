<?php

namespace Tests\Feature;

use App\Models\Extension;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_phone_dashboard(): void
    {
        $this->get('/telefone')->assertRedirect('/entrar');
    }

    public function test_login_page_does_not_expose_company_names_or_codes(): void
    {
        $this->extension();
        $this->get('/entrar')->assertOk()->assertDontSee('Empresa Teste')->assertDontSee('empresa-teste')->assertDontSee('name="tenant"', false);
    }

    public function test_login_assets_use_https_behind_the_reverse_proxy(): void
    {
        $this->withHeaders([
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'pabx.thconect.com.br',
            'X-Forwarded-Port' => '443',
        ])->get('/entrar')
            ->assertOk()
            ->assertSee('https://pabx.thconect.com.br/build/assets/', false)
            ->assertDontSee('http://pabx.thconect.com.br/build/assets/', false);
    }

    public function test_internal_extension_authenticates_without_calling_the_legacy_dialer(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        Http::fake();
        $extension = $this->extension();

        $this->post('/entrar', ['email' => $extension->user->email, 'password' => 'SenhaSIP#Segura'])->assertRedirect('/telefone');
        $this->assertSame($extension->id, session('sip_agent.extension_id'));
        $this->get('/telefone')->assertOk()->assertSee('sip:t'.$extension->tenant_id.'-e999@localhost', false);
        Http::assertNothingSent();
    }

    public function test_wrong_sip_password_does_not_open_phone(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $extension = $this->extension();
        $this->post('/entrar', ['email' => $extension->user->email, 'password' => 'senha-errada'])->assertSessionHasErrors('email');
        $this->assertFalse(session()->has('sip_agent'));
    }

    public function test_dashboard_can_be_refreshed_without_entering_the_password_again(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $extension = $this->extension();
        $this->post('/entrar', ['email' => $extension->user->email, 'password' => 'SenhaSIP#Segura'])->assertRedirect('/telefone');
        $this->get('/telefone')->assertOk();
        $this->get('/telefone')->assertOk();
        $this->assertTrue(session()->has('sip_agent'));
    }

    public function test_generated_extension_password_is_definitive_until_admin_rotates_it(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $extension = $this->extension();
        $extension->update(['secret_rotated_at' => null, 'sip_secret' => 'Abc12345']);

        $this->post('/entrar', ['email' => $extension->user->email, 'password' => 'Abc12345'])->assertRedirect('/telefone');
        $this->assertSame($extension->id, session('sip_agent.extension_id'));
        $this->assertFalse(session()->has('pending_extension_password_change'));
    }

    public function test_first_superadmin_login_is_forced_to_password_change(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $admin = User::factory()->create(['username' => 'superadmin', 'password' => Hash::make('TesteSeguro#2026'), 'role' => 'superadmin', 'must_change_password' => true]);
        $this->post('/administracao/entrar', ['username' => $admin->username, 'password' => 'TesteSeguro#2026'])->assertRedirect('/administracao/primeiro-acesso');
    }

    public function test_superadmin_uses_the_same_email_login_and_can_open_administration(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $tenant = Tenant::create(['name' => 'Empresa Admin', 'slug' => 'empresa-admin', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'admin@thconect.com.br', 'password' => Hash::make('AdminSeguro2026'), 'role' => 'superadmin', 'must_change_password' => false]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'number' => 999, 'sip_username' => "t{$tenant->id}-e999", 'sip_secret' => 'SIPabc12', 'status' => 'active']);

        $this->post('/entrar', ['email' => 'ADMIN@THCONECT.COM.BR', 'password' => 'AdminSeguro2026'])->assertRedirect('/telefone');
        $this->assertAuthenticatedAs($admin);
        $this->assertSame($extension->id, session('sip_agent.extension_id'));
        $this->get('/administracao')->assertOk();
    }

    public function test_company_administrator_is_redirected_to_reports_and_cannot_open_phone_apis(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
        $tenant = Tenant::create(['name' => 'Empresa Restrita', 'slug' => 'empresa-restrita', 'status' => 'active']);
        $admin = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'gestor@empresa.local', 'password' => Hash::make('Gestor123'), 'role' => 'tenant_admin', 'must_change_password' => false]);
        $extension = Extension::create(['tenant_id' => $tenant->id, 'user_id' => $admin->id, 'number' => 999, 'sip_username' => "t{$tenant->id}-e999", 'sip_secret' => 'SipAdm12', 'status' => 'active']);

        $this->post('/entrar', ['email' => $admin->email, 'password' => 'Gestor123'])->assertRedirect('/administracao/acompanhamento');
        $this->assertAuthenticatedAs($admin);
        $this->assertFalse(session()->has('sip_agent'));
        $this->get('/telefone')->assertRedirect('/administracao/acompanhamento');

        $this->withSession(['sip_agent' => ['user_id' => $admin->id, 'tenant_id' => $tenant->id, 'extension_id' => $extension->id, 'extension' => '999']])
            ->postJson('/telefone/chamadas', ['direction' => 'outgoing', 'remote_number' => '81999999999'])->assertForbidden();
    }

    private function extension(): Extension
    {
        $tenant = Tenant::create(['name' => 'Empresa Teste', 'slug' => 'empresa-teste', 'status' => 'active', 'record_calls' => true]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        return Extension::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'number' => 999, 'sip_username' => "t{$tenant->id}-e999", 'sip_secret' => 'SenhaSIP#Segura', 'status' => 'active', 'secret_rotated_at' => now()]);
    }
}
