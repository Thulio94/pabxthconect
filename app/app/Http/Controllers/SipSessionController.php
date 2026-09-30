<?php

namespace App\Http\Controllers;

use App\Models\Extension;
use App\Models\ExtensionPresence;
use App\Models\User;
use App\Services\OperatorActivityRecorder;
use App\Services\PhoneLicenseException;
use App\Services\PhoneLicenseLeaseReaper;
use App\Services\PhoneLicenseManager;
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
        if ($request->user()?->isTenantAdmin()) {
            return redirect()->route('admin.supervision.index');
        }

        if ($request->user()?->isSupervisor()) {
            return redirect()->route('supervisor.dashboard');
        }

        return $request->session()->has('sip_agent') ? redirect()->route('phone.dashboard') : view('auth.login');
    }

    public function store(Request $request, OperatorActivityRecorder $activity, PhoneLicenseManager $licenses, PhoneLicenseLeaseReaper $licenseReaper): RedirectResponse
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

        if ($user?->isSupervisor()) {
            return back()->withErrors(['email' => 'Esta conta acessa o acompanhamento pelo portal administrativo.'])->onlyInput('email');
        }

        $extension = $user?->pbxExtension;
        $validPassword = $user && (Hash::check($data['password'], $user->password)
            || ($extension && hash_equals($extension->sip_secret, (string) $data['password'])));

        if (! $user || ! $extension || ! $validPassword || $extension->status !== 'active'
            || $user->tenant_id !== $extension->tenant_id || $user->tenant?->status !== 'active') {
            return back()->withErrors(['email' => 'E-mail ou senha inválidos.'])->onlyInput('email');
        }

        if ($licenses->requiresLicense($user)) {
            $licenseReaper->reap($extension->tenant_id);
        }

        if ($licenses->requiresLicense($user) && $licenses->hasActiveLease($extension)) {
            return back()->withErrors(['email' => 'Este agente já está conectado à tela de telefonia em outro computador.'])->onlyInput('email');
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();

        if ($user->isTenantAdmin()) {
            $request->session()->forget('sip_agent');

            return redirect()->route('admin.supervision.index');
        }

        try {
            if ($licenses->requiresLicense($user)) {
                $licenses->acquire($user, $extension, $request->session()->getId());
            }
            $operatorSession = $activity->login($request, $user, $extension);
        } catch (PhoneLicenseException $exception) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('phone.login')->withErrors(['email' => $exception->getMessage()]);
        } catch (\Throwable $exception) {
            if ($licenses->requiresLicense($user)) {
                $licenses->releaseForSession($extension, $request->session()->getId());
            }
            report($exception);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('phone.login')->withErrors(['email' => 'Não foi possível iniciar a sessão de telefonia.']);
        }

        $request->session()->put('sip_agent', [
            'user_id' => $user->id,
            'tenant_id' => $extension->tenant_id,
            'extension_id' => $extension->id,
            'extension' => (string) $extension->number,
            'email' => $user->email,
            'role' => $user->role,
            'license_session_key' => $licenses->requiresLicense($user) ? $request->session()->getId() : null,
        ]);
        $request->session()->put('sip_agent.operator_session_id', $operatorSession->id);

        return redirect()->route('phone.dashboard');
    }

    public function destroy(Request $request, OperatorActivityRecorder $activity, PhoneLicenseManager $licenses): RedirectResponse
    {
        $agent = $request->session()->get('sip_agent');
        if ($request->user() && $agent && ($extension = Extension::find($agent['extension_id'] ?? null))) {
            $activity->logout($extension, $request->user(), $agent['operator_session_id'] ?? null);
            if ($licenses->requiresLicense($request->user())) {
                $licenses->releaseForSession($extension, (string) ($agent['license_session_key'] ?? $request->session()->getId()));
            }
            ExtensionPresence::updateOrCreate(['extension_id' => $extension->id], ['pause_reason_id' => null, 'state' => 'offline', 'state_since' => now(), 'heartbeat_at' => now()]);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('phone.login');
    }
}
