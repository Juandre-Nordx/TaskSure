<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $guarded = [];

    const STATUSES = ['assigned' => 'Assigned', 'in_progress' => 'In progress', 'submitted' => 'Submitted for review', 'changes_requested' => 'Changes requested', 'approved' => 'Approved', 'cancelled' => 'Cancelled'];

    const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    protected function casts(): array
    {
        return ['required_evidence' => 'array', 'scheduled_at' => 'datetime', 'due_at' => 'datetime', 'started_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
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
        return ! in_array($this->status, ['approved', 'cancelled']) && $this->due_at->isPast();
    }

    public function scopeVisible(Builder $q, User $user): void
    {
        if ($user->role === 'employee') {
            $q->where('employee_id', $user->id);
        } elseif ($user->role === 'manager') {
            $q->whereIn('employee_id', $user->employees()->select('users.id'));
        }
    }
}
