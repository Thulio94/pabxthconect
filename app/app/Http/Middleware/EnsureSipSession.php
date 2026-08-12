<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSipSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $agent = $request->session()->get('sip_agent');
        if (! $request->user() || ! $agent || (int) ($agent['user_id'] ?? 0) !== $request->user()->id) {
            $request->session()->forget('sip_agent');
            return redirect()->route('phone.login');
        }

        return $next($request);
    }
}
