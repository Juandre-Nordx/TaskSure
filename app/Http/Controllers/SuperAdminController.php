<?php

namespace App\Http\Controllers;

use App\Models\AccountAudit;
use App\Models\Company;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SuperAdminController extends Controller
{
    public function index()
    {
        return view('platform.stores', ['stores' => Store::with(['company', 'owner'])->orderBy('name')->paginate(25)]);
    }

    public function edit(?Store $store = null)
    {
        return view('platform.store-form', ['store' => $store, 'companies' => Company::orderBy('name')->get(), 'owners' => User::where('role', 'admin')->where('active', true)->orderBy('name')->get()]);
    }

    public function save(Request $request, ?Store $store = null)
    {
        foreach (['name', 'company_name', 'owner_name', 'owner_email'] as $key) {
            if ($request->has($key)) {
                $request->merge([$key => trim((string) $request->input($key))]);
            }
        }
        $data = $request->validate([
            'name' => 'required|string|max:120', 'active' => 'required|boolean',
            'company_id' => $store ? 'prohibited' : 'nullable|integer|exists:companies,id',
            'company_name' => $store ? 'prohibited' : 'nullable|required_without:company_id|string|max:120',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('role', 'admin')->where('active', true)],
            'owner_name' => 'nullable|required_without:owner_id|string|max:100',
            'owner_email' => ['nullable', 'required_without:owner_id', 'email', 'max:180', Rule::unique('users', 'email')],
            'role' => 'prohibited', 'password' => 'prohibited',
        ]);
        [$store, $newOwner] = DB::transaction(function () use ($data, $store, $request) {
            if ($store) {
                $store = Store::lockForUpdate()->findOrFail($store->id);
            } else {
                $company = ! empty($data['company_id']) ? Company::findOrFail($data['company_id']) : Company::create(['name' => $data['company_name'], 'is_primary' => false]);
                $store = empty($data['company_id']) ? $company->stores()->firstOrFail() : new Store(['company_id' => $company->id]);
            }
            $newOwner = empty($data['owner_id']);
            $owner = $newOwner ? User::withoutEvents(fn () => User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => Str::password(64), 'role' => 'admin', 'active' => true, 'company_id' => $store->company_id])) : User::where('role', 'admin')->where('active', true)->lockForUpdate()->findOrFail($data['owner_id']);
            $oldOwner = $store->owner_id;
            $store->fill(['name' => $data['name'], 'active' => $data['active'], 'owner_id' => $owner->id])->save();
            if ($oldOwner && $oldOwner !== $owner->id) {
                $store->users()->detach($oldOwner);
            }
            $store->users()->syncWithoutDetaching([$owner->id => ['active' => true]]);
            AccountAudit::create(['actor_id' => $request->user()->id, 'store_id' => $store->id, 'company_id' => $store->company_id, 'action' => 'platform_store_saved', 'data' => ['name' => $store->name, 'active' => $store->active, 'old_owner_id' => $oldOwner, 'owner_id' => $owner->id]]);

            return [$store, $newOwner];
        });
        $message = 'Store saved.';
        if ($newOwner) {
            $message .= ' '.$this->invitation($store->owner);
        }

        return redirect('/super-admin')->with('success', $message);
    }

    public function invite(Store $store)
    {
        abort_unless($store->owner?->role === 'admin' && $store->owner->active, 422, 'Assign an active store owner first.');

        return back()->with('success', $this->invitation($store->owner));
    }

    private function invitation(User $owner): string
    {
        if (in_array(config('mail.default'), ['log', 'array'])) {
            return 'Owner account created. Configure SMTP, then use Send setup link. No password was emailed.';
        }
        try {
            $status = Password::sendResetLink(['email' => $owner->email]);

            return $status === Password::RESET_LINK_SENT ? 'A secure password setup link was sent to the owner.' : 'Setup link could not be sent yet. Retry Send setup link.';
        } catch (\Throwable $error) {
            report($error);

            return 'Owner saved, but setup email failed. Check SMTP and retry Send setup link.';
        }
    }
}
