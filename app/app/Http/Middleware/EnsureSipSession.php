<?php

namespace App\Http\Middleware;

use App\Models\Extension;
use App\Services\PhoneLicenseManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSipSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $licenses = app(PhoneLicenseManager::class);

        if ($request->user()?->isTenantAdmin()) {
            $request->session()->forget('sip_agent');

            return $request->expectsJson()
                ? response()->json(['message' => 'O administrador da empresa possui acesso somente administrativo.'], 403)
                : redirect()->route('admin.supervision.index');
        }

        $agent = $request->session()->get('sip_agent');
        if (! $request->user() || ! $agent || (int) ($agent['user_id'] ?? 0) !== $request->user()->id) {
            $request->session()->forget('sip_agent');

            return $request->expectsJson()
                ? response()->json(['message' => 'Sua sessão foi encerrada.', 'session_ended' => true], 401)
                : redirect()->route('phone.login');
        }

        $extension = Extension::query()->with('tenant')->find($agent['extension_id'] ?? null);
        $licenseRequired = $licenses->requiresLicense($request->user());
        $valid = $extension
            && $extension->user_id === $request->user()->id
            && $extension->status === 'active'
            && $extension->tenant?->status === 'active'
            && (! $licenseRequired || $licenses->hasLeaseForSession(
                $request->user(),
                $extension,
                (string) ($agent['license_session_key'] ?? $request->session()->getId())
            ));

        if (! $valid) {
            $request->session()->forget('sip_agent');
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Seu acesso à telefonia foi encerrado pelo administrador.', 'session_ended' => true], 401)
                : redirect()->route('phone.login')->withErrors(['email' => 'Seu acesso à telefonia foi encerrado pelo administrador.']);
        }

        return $next($request);
    }
}
