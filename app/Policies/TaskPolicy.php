<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $u, Task $t): bool
    {
        return $u->active && ($u->role === 'admin' || ($u->role === 'employee' && $t->employee_id === $u->id) || ($u->role === 'manager' && $u->canManageEmployee($t->employee)));
    }

    public function manage(User $u, Task $t): bool
    {
        return $u->active && $u->isSupervisor() && $this->view($u, $t);
    }

    public function work(User $u, Task $t): bool
    {
        return $u->active && $u->role === 'employee' && $t->employee_id === $u->id && ! in_array($t->status, ['approved', 'cancelled']);
    }
}
