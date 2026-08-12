<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Extension;
use App\Models\ExtensionPresence;
use App\Services\OperatorActivityRecorder;
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
        if ($request->user()?->isTenantAdmin()) return redirect()->route('admin.supervision.index');

        return $request->session()->has('sip_agent') ? redirect()->route('phone.dashboard') : view('auth.login');
    }

    public function store(Request $request, OperatorActivityRecorder $activity): RedirectResponse
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

        if ($user->isTenantAdmin()) {
            $request->session()->forget('sip_agent');
            return redirect()->route('admin.supervision.index');
        }

        $request->session()->put('sip_agent', [
            'user_id' => $user->id,
            'tenant_id' => $extension->tenant_id,
            'extension_id' => $extension->id,
            'extension' => (string) $extension->number,
            'email' => $user->email,
            'role' => $user->role,
        ]);
        $operatorSession = $activity->login($request, $user, $extension);
        $request->session()->put('sip_agent.operator_session_id', $operatorSession->id);

        return redirect()->route('phone.dashboard');
    }

    public function destroy(Request $request, OperatorActivityRecorder $activity): RedirectResponse
    {
        $agent = $request->session()->get('sip_agent');
        if ($request->user() && $agent && ($extension = Extension::find($agent['extension_id'] ?? null))) {
            $activity->logout($extension, $request->user(), $agent['operator_session_id'] ?? null);
            ExtensionPresence::updateOrCreate(['extension_id' => $extension->id], ['pause_reason_id' => null, 'state' => 'offline', 'state_since' => now(), 'heartbeat_at' => now()]);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('phone.login');
    }
}
