<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SipSessionController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        return $request->session()->has('sip_agent') ? redirect()->route('phone.dashboard') : view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $email = Str::lower(trim($data['email']));

        $key = 'pbx-login:'.$email.'|'.$request->ip();
        $ipKey = 'pbx-login-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8) || RateLimiter::tooManyAttempts($ipKey, 30)) {
            return back()->withErrors(['email' => 'Muitas tentativas. Aguarde um minuto e tente novamente.'])->onlyInput('email');
        }
        RateLimiter::hit($key, 60);
        RateLimiter::hit($ipKey, 60);

        $user = User::query()->with(['tenant', 'pbxExtension'])
            ->whereRaw('LOWER(email) = ?', [$email])->first();
        $extension = $user?->pbxExtension;
        $validPassword = $user && (Hash::check($data['password'], $user->password)
            || ($extension && hash_equals($extension->sip_secret, (string) $data['password'])));

        if (! $user || ! $extension || ! $validPassword || $extension->status !== 'active'
            || $user->tenant_id !== $extension->tenant_id || $user->tenant?->status !== 'active') {
            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('sip_agent', [
            'user_id' => $user->id,
            'tenant_id' => $extension->tenant_id,
            'extension_id' => $extension->id,
            'extension' => (string) $extension->number,
            'email' => $user->email,
            'role' => $user->role,
        ]);

        return redirect()->route('phone.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('phone.login');
    }
}
