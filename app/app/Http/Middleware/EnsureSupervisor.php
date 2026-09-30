<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSupervisor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user?->isSupervisor() && $user->tenant_id && $user->tenant?->status === 'active', 403);

        return $next($request);
    }
}
