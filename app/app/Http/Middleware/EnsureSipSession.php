<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSipSession
{
    public function handle(Request $request, Closure $next): Response
    {
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

        return $next($request);
    }
}
