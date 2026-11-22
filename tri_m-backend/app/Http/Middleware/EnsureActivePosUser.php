<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActivePosUser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()->is_active && $request->user()->hasRole('owner', 'cashier'), 403, 'This account cannot access the POS.');

        return $next($request);
    }
}
