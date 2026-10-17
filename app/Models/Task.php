<?php

namespace App\Models;

use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            $task->store_id ??= app(StoreContext::class)->store?->id ?? $task->employee->storeId();
        });
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    const STATUSES = ['assigned' => 'Assigned', 'in_progress' => 'In progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];

    protected $attributes = ['review_status' => 'not_reviewed'];

    const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected function casts(): array
    {
        return ['store_id' => 'integer', 'company_id' => 'integer', 'required_evidence' => 'array', 'scheduled_at' => 'datetime', 'due_at' => 'datetime', 'started_at' => 'datetime', 'approved_at' => 'datetime', 'completed_at' => 'datetime', 'reviewed_at' => 'datetime', 'schedule_removed' => 'boolean'];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function template()
    {
        return $this->belongsTo(TaskTemplate::class, 'task_template_id');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviews()
    {
        return $this->hasMany(TaskReview::class)->orderBy('id');
    }

    public function currentCompletion()
    {
        return $this->belongsTo(Submission::class, 'current_completion_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class)->orderBy('id');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }

    public function activities()
    {
        return $this->hasMany(TaskActivity::class)->latest('id');
    }

    public function deadlineChanges()
    {
        return $this->hasMany(DeadlineChange::class);
    }

    public function overdue(): bool
    {
        return ! in_array($this->status, ['completed', 'cancelled']) && $this->due_at->isPast();
    }

    public function scopeVisible(Builder $q, User $user): void
    {
        $q->where('store_id', $user->storeId())->whereIn('employee_id', $user->visibleEmployees()->select('users.id'));
    }
}
