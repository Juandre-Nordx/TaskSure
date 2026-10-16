<?php

namespace App\Models;

use App\Support\StoreContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TaskTemplate extends Model
{
    public const FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];

    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (TaskTemplate $template) {
            $template->store_id ??= app(StoreContext::class)->store?->id ?? $template->employee->storeId();
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'company_id' => 'integer', 'required_evidence' => 'array', 'active' => 'boolean', 'next_date' => 'date', 'ends_on' => 'date', 'anchor_day' => 'integer', 'scheduled_offset_minutes' => 'integer'];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function scopeManageable(Builder $query, User $user): void
    {
        $query->where('store_id', $user->storeId())
            ->whereIn('employee_id', $user->visibleEmployees()->select('users.id'))
            ->when($user->role !== 'admin', fn ($q) => $q->where('creator_id', $user->id))
            ->when(! $user->isSupervisor(), fn ($q) => $q->whereRaw('1 = 0'));
    }

    public function manageableBy(User $user): bool
    {
        return $user->isSupervisor() && $user->storeId() === $this->store_id && $user->canManageEmployee($this->employee, $this->store_id)
            && ($user->role === 'admin' || $this->creator_id === $user->id);
    }

    public function generationBlock(): ?string
    {
        if (! $this->store->active) {
            return 'Store inactive';
        }
        if ($this->ends_on && $this->next_date->gt($this->ends_on)) {
            return 'Finished';
        }
        if (! $this->active) {
            return 'Paused';
        }
        if (! $this->employee->inStore($this->store_id)) {
            return 'Assignee inactive';
        }
        if (! $this->creator->canManageEmployee($this->employee, $this->store_id)) {
            return 'Creator no longer has assignment permission';
        }

        return null;
    }

    public function followingDate(string $date): string
    {
        $day = CarbonImmutable::parse($date, config('tasksure.timezone'));

        return match ($this->frequency) {
            'daily' => $day->addDay()->toDateString(),
            'weekly' => $day->addWeek()->toDateString(),
            'monthly' => $day->startOfMonth()->addMonth()->day(min($this->anchor_day ?? $day->day, $day->startOfMonth()->addMonth()->daysInMonth))->toDateString(),
        };
    }
}
