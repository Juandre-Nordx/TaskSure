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

    protected $fillable = ['name', 'email', 'password', 'role', 'active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'email_verified_at' => 'datetime'];
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
        return $this->active && in_array($employee->role, self::ASSIGNEE_ROLES)
            && ($this->role === 'admin' || ($this->role === 'manager'
                && ($this->id === $employee->id || $this->employees()->whereKey($employee->id)->exists())));
    }

    public function visibleEmployees()
    {
        return self::query()->whereIn('role', self::ASSIGNEE_ROLES)
            ->when($this->role === 'manager', fn ($q) => $q->where(fn ($q) => $q->whereKey($this->id)->orWhereIn('id', $this->employees()->select('users.id'))))
            ->when($this->role === 'employee', fn ($q) => $q->whereKey($this->id))
            ->when(! in_array($this->role, ['admin', 'manager', 'employee']), fn ($q) => $q->whereRaw('1 = 0'));
    }
}
