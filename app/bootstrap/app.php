<?php

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsureSipSession;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureOperationAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A aplicação não publica o PHP-FPM diretamente; todas as requisições
        // externas chegam pelo Traefik do Easypanel e pelo Nginx do Compose.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'password.changed' => EnsurePasswordChanged::class,
            'sip.session' => EnsureSipSession::class,
            'superadmin' => EnsureSuperAdmin::class,
            'operation.admin' => EnsureOperationAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
