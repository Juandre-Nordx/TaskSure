<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use App\Support\BusinessTime;

class TaskQuery
{
    public function build(User $u, array $f)
    {
        $q = Task::visible($u);
        if (($f['scope'] ?? null) === 'my') {
            $q->where('employee_id', $u->id);
        } elseif (($f['scope'] ?? null) === 'team') {
            abort_unless($u->isSupervisor(), 403);
            $q->where('employee_id', '!=', $u->id);
        }
        foreach (['employee_id', 'category_id', 'priority', 'status'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$f['q'].'%')->orWhere('area', 'like', '%'.$f['q'].'%'));
        }
        if (! empty($f['overdue'])) {
            $q->whereNotIn('status', ['completed', 'cancelled'])->where('due_at', '<', now());
        }
        if (! empty($f['review_state'])) {
            $q->where('status', 'completed')->where('review_status', $f['review_state']);
        }
        $basis = match ($f['basis'] ?? 'due') {
            'assigned' => 'created_at', 'completed' => 'completed_at', 'reviewed' => 'reviewed_at', default => 'due_at'
        };
        if (! empty($f['from'])) {
            $q->where($basis, '>=', BusinessTime::utc($f['from'].' 00:00:00'));
        }
        if (! empty($f['to'])) {
            $q->where($basis, '<', BusinessTime::utc($f['to'].' 00:00:00')->addDay());
        }

        return $q;
    }
}
