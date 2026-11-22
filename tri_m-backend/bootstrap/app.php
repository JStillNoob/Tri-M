<?php

use App\Http\ApiProblem;
use App\Http\Middleware\EnsureActivePosUser;
use App\Http\Middleware\RequirePosRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        if (getenv('RENDER') === 'true') {
            $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO);
        }

        $middleware->statefulApi();
        $middleware->alias([
            'pos.active' => EnsureActivePosUser::class,
            'pos.role' => RequirePosRole::class,
        ]);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureActivePosUser::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, RequirePosRole::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->respond(ApiProblem::respond(...));
    })->create();
