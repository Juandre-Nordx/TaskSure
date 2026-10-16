<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->active && $request->user()->role === 'super_admin', 403);

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
