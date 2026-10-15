<?php

namespace App\Http\Middleware;

use App\Services\CompanyPolicyService;
use Closure;
use Illuminate\Http\Request;

class RequireCompanyPolicy
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_unless($user?->company_id, 403, 'A company account is required.');
        if (! $request->routeIs('company-policy.*', 'mobile.policy.*', 'logout', 'mobile.logout', 'mobile.me')) {
            $policy = app(CompanyPolicyService::class)->requiredFor($user);
            if ($policy) {
                if ($request->is('api/*', 'calendar/events') || $request->expectsJson()) {
                    return response()->json(['code' => CompanyPolicyService::REQUIRED, 'message' => 'Read and acknowledge your current company policy to continue.', 'policy' => $policy->toPolicyData()], 423)->header('Cache-Control', 'private, no-store');
                }
                if ($request->isMethod('GET') && ! $request->session()->has('policy.intended')) {
                    $request->session()->put('policy.intended', $request->getRequestUri());
                }

                return redirect()->route('company-policy.show')->header('Cache-Control', 'private, no-store')->header('X-TaskSure-Code', CompanyPolicyService::REQUIRED);
            }
        }

        return $next($request);
    }
}
