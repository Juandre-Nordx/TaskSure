<?php

use App\Models\AccountAudit;
use App\Models\User;
use App\Services\ScheduledTasks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('tasks:generate', function (ScheduledTasks $s) {
    $this->info('Generated '.$s->occurrences().' occurrences.');
});
Artisan::command('tasks:remind', function (ScheduledTasks $s) {
    $this->info('Checked '.$s->reminders().' tasks.');
});
Artisan::command('tasksure:admin {email} {name}', function () {
    $email = $this->argument('email');
    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return $this->error('Invalid email.');
    }if (User::where('email', $email)->exists()) {
        return $this->error('Account exists.');
    }$password = $this->secret('New administrator password (12+ characters, mixed case, number)');
    if (strlen($password ?? '') < 12 || ! preg_match('/[A-Z]/', $password) || ! preg_match('/[a-z]/', $password) || ! preg_match('/[0-9]/', $password)) {
        return $this->error('Password does not meet requirements.');
    }$u = User::create(['email' => $email, 'name' => $this->argument('name'), 'role' => 'admin', 'active' => true, 'password' => $password]);
    AccountAudit::create(['subject_id' => $u->id, 'action' => 'bootstrap_admin']);
    $this->info('Administrator created.');
});
Schedule::command('tasks:generate')->everyMinute()->withoutOverlapping();
Schedule::command('tasks:remind')->everyMinute()->withoutOverlapping();
