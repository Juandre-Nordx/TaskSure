<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory,Notifiable;

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
        return $employee->role === 'employee' && ($this->role === 'admin' || ($this->role === 'manager' && $this->employees()->whereKey($employee->id)->exists()));
    }

    public function visibleEmployees()
    {
        return self::query()->where('role', 'employee')->when($this->role === 'manager', fn ($q) => $q->whereIn('id', $this->employees()->select('users.id')))->when($this->role === 'employee', fn ($q) => $q->whereKey($this->id));
    }
}
