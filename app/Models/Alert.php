<?php

namespace App\Models;

use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class Alert extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Alert $alert) {
            if ($alert->type !== 'policy_published') {
                $alert->store_id ??= $alert->task?->store_id ?? $alert->user->storeId();
            }
            $alert->company_id ??= $alert->store?->company_id ?? $alert->user->workspaceCompanyId();
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function scopeVisibleTo($query, User $user): void
    {
        $query->where('user_id', $user->id)->where(fn ($q) => $q->where('store_id', $user->storeId())->orWhere(fn ($q) => $q->whereNull('store_id')->where('company_id', $user->workspaceCompanyId())));
    }

    public function deliverable(): bool
    {
        if (! $this->user->active) {
            return false;
        }
        if ($this->store_id) {
            if (! $this->user->inStore($this->store_id)) {
                return false;
            }

            return ! $this->task || app(StoreContext::class)->run($this->store, fn () => Gate::forUser($this->user)->allows('view', $this->task));
        }

        return $this->user->stores()->wherePivot('active', true)->where('company_id', $this->company_id)->where('stores.active', true)->exists();
    }

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'company_id' => 'integer', 'read_at' => 'datetime', 'emailed_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function task()
    {
        return $this->belongsTo(Task::class);
    }
}
