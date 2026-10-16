<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $u, Task $t): bool
    {
        return $u->inStore($t->store_id) && $u->storeId() === $t->store_id && ($u->role === 'admin' || (in_array($u->role, User::ASSIGNEE_ROLES) && $t->employee_id === $u->id) || ($u->role === 'manager' && $u->canManageEmployee($t->employee, $t->store_id)));
    }

    public function manage(User $u, Task $t): bool
    {
        return $u->active && $u->isSupervisor() && $this->view($u, $t);
    }

    public function work(User $u, Task $t): bool
    {
        return $this->view($u, $t) && in_array($u->role, User::ASSIGNEE_ROLES) && $t->employee_id === $u->id && in_array($t->status, ['assigned', 'in_progress']);
    }

    public function review(User $u, Task $t): bool
    {
        return $this->manage($u, $t) && $t->employee_id !== $u->id;
    }
}
