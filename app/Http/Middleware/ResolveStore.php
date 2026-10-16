<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ResolveStore
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->routeIs('logout', 'mobile.logout')) {
            return $next($request);
        }
        $user = $request->user();
        if ($user->role === 'super_admin') {
            return $request->path() === '/' ? redirect('/super-admin') : abort(403, 'Use the Super Admin dashboard. Store task access is not available.');
        }
        abort_unless(Schema::hasTable('stores'), 403, 'A company account is required. Complete the company/store migration.');
        $token = $request->is('api/*') ? $user->currentAccessToken() : null;
        $selected = $token ? $token->store_id : $request->session()->get('workspace.store_id');
        if (! $selected) {
            $selected = $user->stores()->wherePivot('active', true)->orderByDesc('stores.active')->orderBy('stores.id')->value('stores.id');
            if ($token) {
                $token->forceFill(['store_id' => $selected])->save();
            } else {
                $request->session()->put('workspace.store_id', $selected);
            }
        }
        $store = $selected && $user->inStore((int) $selected, false) ? Store::with('company')->find($selected) : null;
        $allow = $request->routeIs('stores.*', 'mobile.stores.*', 'mobile.me');
        if (! $allow) {
            $claimed = $request->header('X-TaskSure-Store') ?? $request->input('_workspace_store_id');
            if ($claimed !== null && (string) $claimed !== (string) $selected) {
                return $this->blocked($request, 'STORE_CONTEXT_CHANGED', 'Your selected store changed. Reload before continuing.', 409);
            }
            if (! $store) {
                return $this->blocked($request, 'STORE_ACCESS_REQUIRED', 'Store access is unavailable. Choose an authorised store or contact your administrator.');
            }
            if (! $store->active) {
                return $this->blocked($request, 'STORE_INACTIVE', 'This store is inactive. Contact your administrator or choose another store.');
            }
        }
        $context = app(StoreContext::class);
        $before = [$context->resolved, $context->store];
        $context->resolved = true;
        $context->store = $store;
        try {
            $response = $next($request);
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } finally {
            [$context->resolved, $context->store] = $before;
        }
    }

    private function blocked(Request $request, string $code, string $message, int $status = 403)
    {
        return $request->is('api/*') || $request->expectsJson()
            ? response()->json(compact('code', 'message'), $status)->header('Cache-Control', 'private, no-store')
            : response()->view('stores.unavailable', compact('code', 'message'), $status)->header('Cache-Control', 'private, no-store');
    }
}
