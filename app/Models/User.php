<?php

namespace App\Models;

use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens,HasFactory,Notifiable;

    public const ASSIGNEE_ROLES = ['employee', 'manager'];

    protected $fillable = ['name', 'email', 'password', 'role', 'active', 'company_id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'email_verified_at' => 'datetime', 'company_id' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            // Trusted CLI/factory creation retains the existing one-business deployment default.
            // HTTP account creation explicitly takes its company from the authenticated owner.
            $user->company_id ??= Company::where('is_primary', true)->value('id');
        });
        static::created(function (User $user) {
            // Trusted CLI/factory creation; HTTP management creates memberships explicitly.
            if ($user->role !== 'super_admin' && Schema::hasTable('stores')) {
                $store = Store::where('company_id', $user->company_id)->orderBy('id')->first();
                if ($store) {
                    $user->stores()->attach($store->id, ['active' => true]);
                    if ($user->role === 'admin' && ! $store->owner_id) {
                        $store->update(['owner_id' => $user->id]);
                    }
                }
            }
        });
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function policyAcknowledgements()
    {
        return $this->hasMany(CompanyPolicyAcknowledgement::class);
    }

    public function employees()
    {
        return $this->belongsToMany(self::class, 'store_manager_employee', 'manager_id', 'employee_id')->withPivotValue('store_id', $this->storeId());
    }

    public function managers()
    {
        return $this->belongsToMany(self::class, 'store_manager_employee', 'employee_id', 'manager_id')->withPivotValue('store_id', $this->storeId());
    }

    public function stores()
    {
        return $this->belongsToMany(Store::class)->withPivot('active');
    }

    public function storeId(): ?int
    {
        $context = app(StoreContext::class);

        return $context->resolved ? $context->store?->id : $this->stores()->wherePivot('active', true)->orderByDesc('stores.active')->orderBy('stores.id')->value('stores.id');
    }

    public function workspaceCompanyId(): ?int
    {
        return Store::whereKey($this->storeId())->value('company_id');
    }

    public function inStore(?int $id, bool $requireActiveStore = true): bool
    {
        return $id && $this->active && $this->role !== 'super_admin' && $this->stores()->wherePivot('active', true)->whereKey($id)->when($requireActiveStore, fn ($q) => $q->where('stores.active', true))->exists();
    }

    public function membersInStore(?int $storeId = null)
    {
        return self::whereHas('stores', fn ($q) => $q->where('stores.id', $storeId ?? $this->storeId()));
    }

    public function isSupervisor(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    public function canManageEmployee(User $employee, ?int $storeId = null): bool
    {
        $storeId ??= $this->storeId();

        return $this->inStore($storeId) && $employee->stores()->whereKey($storeId)->exists() && in_array($employee->role, self::ASSIGNEE_ROLES)
            && ($this->role === 'admin' || ($this->role === 'manager'
                && ($this->id === $employee->id || DB::table('store_manager_employee')->where('store_id', $storeId)->where('manager_id', $this->id)->where('employee_id', $employee->id)->exists())));
    }

    public function visibleEmployees()
    {
        return $this->membersInStore()->whereIn('role', self::ASSIGNEE_ROLES)
            ->when($this->role === 'manager', fn ($q) => $q->where(fn ($q) => $q->whereKey($this->id)->orWhereIn('id', $this->employees()->select('users.id'))))
            ->when($this->role === 'employee', fn ($q) => $q->whereKey($this->id))
            ->when(! in_array($this->role, ['admin', 'manager', 'employee']), fn ($q) => $q->whereRaw('1 = 0'));
    }

    public function assignableEmployees()
    {
        return $this->visibleEmployees()->where('active', true)
            ->whereHas('stores', fn ($q) => $q->where('stores.id', $this->storeId())->where('store_user.active', true));
    }
}
