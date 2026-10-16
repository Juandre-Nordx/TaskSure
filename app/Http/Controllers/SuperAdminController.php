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
use Illuminate\Validation\Rules\Password as PasswordRule;

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
            'role' => 'prohibited',
            'password' => filled($request->input('owner_id')) ? ['prohibited'] : $this->passwordRules(false),
            'password_confirmation' => filled($request->input('owner_id')) ? ['prohibited'] : ['nullable', 'required_with:password', 'string', 'max:72'],
        ]);
        [$store, $newOwner] = DB::transaction(function () use ($data, $store, $request) {
            if ($store) {
                $store = Store::lockForUpdate()->findOrFail($store->id);
            } else {
                $company = ! empty($data['company_id']) ? Company::findOrFail($data['company_id']) : Company::create(['name' => $data['company_name'], 'is_primary' => false]);
                $store = empty($data['company_id']) ? $company->stores()->firstOrFail() : new Store(['company_id' => $company->id]);
            }
            $newOwner = empty($data['owner_id']);
            $owner = $newOwner ? User::withoutEvents(fn () => User::create(['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['password'] ?? Str::password(64), 'role' => 'admin', 'active' => true, 'company_id' => $store->company_id])) : User::where('role', 'admin')->where('active', true)->lockForUpdate()->findOrFail($data['owner_id']);
            if ($newOwner && filled($data['password'] ?? null)) {
                Password::deleteToken($owner);
            }
            $oldOwner = $store->owner_id;
            $store->fill(['name' => $data['name'], 'active' => $data['active'], 'owner_id' => $owner->id])->save();
            if ($oldOwner && $oldOwner !== $owner->id) {
                $store->users()->detach($oldOwner);
            }
            $store->users()->syncWithoutDetaching([$owner->id => ['active' => true]]);
            AccountAudit::create(['actor_id' => $request->user()->id, 'store_id' => $store->id, 'company_id' => $store->company_id, 'action' => 'platform_store_saved', 'data' => ['name' => $store->name, 'active' => $store->active, 'old_owner_id' => $oldOwner, 'owner_id' => $owner->id, 'owner_setup' => $newOwner ? (filled($data['password'] ?? null) ? 'password' : 'email') : 'existing']]);

            return [$store, $newOwner];
        });
        $message = 'Store saved.';
        if ($newOwner) {
            $message .= filled($data['password'] ?? null)
                ? ' Owner account created with the supplied password. The owner can sign in on the web.'
                : ' '.$this->invitation($store->owner);
        }

        return redirect('/super-admin')->with('success', $message);
    }

    public function invite(Store $store)
    {
        abort_unless($store->owner?->role === 'admin' && $store->owner->active, 422, 'Assign an active store owner first.');

        return back()->with('success', $this->invitation($store->owner));
    }

    private function passwordRules(bool $required): array
    {
        return ['bail', $required ? 'required' : 'nullable', 'required_with:password_confirmation', 'string', 'max:72', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers(),
            static function ($attribute, $value, $fail) {
                // Bcrypt uses at most 72 bytes, including multibyte characters.
                if (strlen($value) > 72) {
                    $fail('This password is too long. Use fewer characters.');
                }
                if (str_contains($value, "\0")) {
                    $fail('This password contains an unsupported character.');
                }
            }];
    }

    public function ownerPassword(Request $request, Store $store)
    {
        $data = $request->validate([
            'owner_id' => 'required|integer', 'password' => $this->passwordRules(true),
            'password_confirmation' => 'required|string|max:72',
            'user_id' => 'prohibited', 'company_id' => 'prohibited', 'role' => 'prohibited',
        ]);
        DB::transaction(function () use ($request, $store, $data) {
            $store = Store::lockForUpdate()->findOrFail($store->id);
            abort_unless($store->owner_id === (int) $data['owner_id'], 409, 'The assigned owner changed. Reload this page before setting a password.');
            $owner = User::whereKey($store->owner_id)->where('role', 'admin')->where('active', true)->lockForUpdate()->first();
            abort_unless($owner, 422, 'Assign an active store owner first.');
            $owner->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $owner->id)->delete();
            $owner->tokens()->delete();
            Password::deleteToken($owner);
            AccountAudit::create(['actor_id' => $request->user()->id, 'subject_id' => $owner->id, 'store_id' => $store->id, 'company_id' => $store->company_id, 'action' => 'platform_owner_password_set']);
        });

        return redirect('/super-admin/stores/'.$store->id.'/edit')->with('success', 'Owner password saved. Existing sign-ins and setup links for this owner have been revoked.');
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
