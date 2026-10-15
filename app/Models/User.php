<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
        return $this->belongsToMany(self::class, 'manager_employee', 'manager_id', 'employee_id');
    }

    public function managers()
    {
        return $this->belongsToMany(self::class, 'manager_employee', 'employee_id', 'manager_id');
    }

    public function isSupervisor(): bool
    {
        return in_array($this->role, ['admin', 'manager']);
    }

    public function canManageEmployee(User $employee): bool
    {
        return $this->active && $this->company_id !== null && $this->company_id === $employee->company_id && in_array($employee->role, self::ASSIGNEE_ROLES)
            && ($this->role === 'admin' || ($this->role === 'manager'
                && ($this->id === $employee->id || $this->employees()->whereKey($employee->id)->exists())));
    }

    public function visibleEmployees()
    {
        return self::query()->where('company_id', $this->company_id)->whereNotNull('company_id')->whereIn('role', self::ASSIGNEE_ROLES)
            ->when($this->role === 'manager', fn ($q) => $q->where(fn ($q) => $q->whereKey($this->id)->orWhereIn('id', $this->employees()->select('users.id'))))
            ->when($this->role === 'employee', fn ($q) => $q->whereKey($this->id))
            ->when(! in_array($this->role, ['admin', 'manager', 'employee']), fn ($q) => $q->whereRaw('1 = 0'));
    }
}
