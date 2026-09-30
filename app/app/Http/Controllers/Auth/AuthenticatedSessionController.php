<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.admin-login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:80'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::lower($credentials['username']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['username' => 'Acesso temporariamente bloqueado. Aguarde alguns minutos.'])->onlyInput('username');
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['username' => 'Login ou senha inválidos.'])->onlyInput('username');
        }

        $user = $request->user();
        $canUseAdminPortal = $user->isSuperAdmin() || $user->isTenantAdmin() || $user->isSupervisor();
        $tenantIsActive = $user->isSuperAdmin() || ($user->tenant?->status === 'active');
        if (! $canUseAdminPortal || ! $tenantIsActive) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);

            return back()->withErrors(['username' => 'Login ou senha inválidos.'])->onlyInput('username');
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        if ($user->must_change_password) {
            return redirect()->route('password.change.edit');
        }

        return match ($user->role) {
            'superadmin' => redirect()->route('admin.index'),
            'tenant_admin' => redirect()->route('admin.supervision.index'),
            default => redirect()->route('supervisor.dashboard'),
        };
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
