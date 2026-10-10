<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MobileEmployee
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($request->bearerToken() && $user?->currentAccessToken() instanceof PersonalAccessToken, 401, 'Sign in to the employee app.');
        if (! $user->active) {
            $user->tokens()->delete();
            abort(401, 'Your account is inactive. Contact your administrator.');
        }
        abort_unless($user->role === 'employee' && $user->tokenCan('employee'), 403, 'Employee access is required.');

        $response = $next($request);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
