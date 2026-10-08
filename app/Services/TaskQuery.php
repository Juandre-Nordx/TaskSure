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
        foreach (['employee_id', 'category_id', 'priority', 'status'] as $key) {
            if (! empty($f[$key])) {
                $q->where($key, $f[$key]);
            }
        }
        if (! empty($f['q'])) {
            $q->where(fn ($q) => $q->where('title', 'like', '%'.$f['q'].'%')->orWhere('area', 'like', '%'.$f['q'].'%'));
        }
        if (! empty($f['overdue'])) {
            $q->whereNotIn('status', ['approved', 'cancelled'])->where('due_at', '<', now());
        }
        $basis = match ($f['basis'] ?? 'due') {
            'assigned' => 'created_at','approved' => 'approved_at',default => 'due_at'
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
