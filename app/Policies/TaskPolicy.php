<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $u, Task $t): bool
    {
        return $u->active && $u->company_id !== null && $u->company_id === $t->employee->company_id && ($u->role === 'admin' || (in_array($u->role, User::ASSIGNEE_ROLES) && $t->employee_id === $u->id) || ($u->role === 'manager' && $u->canManageEmployee($t->employee)));
    }

    public function manage(User $u, Task $t): bool
    {
        return $u->active && $u->isSupervisor() && $this->view($u, $t);
    }

    public function work(User $u, Task $t): bool
    {
        return $u->active && in_array($u->role, User::ASSIGNEE_ROLES) && $t->employee_id === $u->id && in_array($t->status, ['assigned', 'in_progress']);
    }

    public function review(User $u, Task $t): bool
    {
        return $this->manage($u, $t) && $t->employee_id !== $u->id;
    }
}
