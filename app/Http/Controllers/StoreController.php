<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\MobileAuthController;
use App\Models\AccountAudit;
use App\Models\PushDevice;
use App\Models\Store;
use App\Support\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StoreController extends Controller
{
    public function index(Request $request)
    {
        abort_if($request->user()->role === 'super_admin', 403);
        $stores = $request->user()->stores()->wherePivot('active', true)->with('company')->orderBy('stores.name')->get();

        return $request->is('api/*') ? response()->json(['data' => $stores->map->data()]) : view('stores.choose', compact('stores'));
    }

    public function select(Request $request, MobileAuthController $mobile)
    {
        $data = $request->validate(['store_id' => 'required|integer']);
        abort_unless($request->user()->inStore((int) $data['store_id'], false), 403, 'This store is not authorised for your account.');
        $store = Store::with('company')->findOrFail($data['store_id']);
        if ($request->is('api/*')) {
            DB::transaction(function () use ($request, $store) {
                $token = $request->user()->currentAccessToken()->newQuery()->lockForUpdate()->findOrFail($request->user()->currentAccessToken()->id);
                $token->forceFill(['store_id' => $store->id])->save();
                $request->user()->withAccessToken($token);
                PushDevice::where('personal_access_token_id', $token->id)->delete();
            });

            return response()->json(app(StoreContext::class)->run($store, fn () => $mobile->profile($request->user())));
        }
        $request->session()->regenerate();
        $request->session()->forget(['policy.intended', 'url.intended']);
        $request->session()->put('workspace.store_id', $store->id);

        return redirect('/')->with('success', 'Selected '.$store->name.'.');
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $store = app(StoreContext::class)->store;

        return view('admin.company-settings', ['store' => $store, 'company' => $store->company]);
    }

    public function saveSettings(Request $request)
    {
        abort_unless($request->user()->role === 'admin', 403);
        $request->merge(['company_name' => trim((string) $request->input('company_name')), 'store_name' => trim((string) $request->input('store_name'))]);
        $data = $request->validate(['company_name' => 'required|string|max:120', 'store_name' => 'required|string|max:120', 'company_id' => 'prohibited', 'store_id' => 'prohibited']);
        $store = app(StoreContext::class)->store;
        DB::transaction(function () use ($store, $data, $request) {
            $company = $store->company()->lockForUpdate()->firstOrFail();
            $before = ['company_name' => $company->name, 'store_name' => $store->name];
            $company->update(['name' => $data['company_name']]);
            $store->update(['name' => $data['store_name']]);
            AccountAudit::create(['actor_id' => $request->user()->id, 'action' => 'company_settings_saved', 'data' => compact('before') + $data]);
        });

        return back()->with('success', 'Company and store names saved.');
    }
}
