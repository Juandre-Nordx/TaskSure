<?php

return ['timezone' => 'Africa/Johannesburg', 'upload_max_kb' => (int) env('UPLOAD_MAX_KB', 10240), 'email_enabled' => (bool) env('TASK_EMAIL_ENABLED', false), 'reminder_minutes' => (int) env('REMINDER_MINUTES', 60)];
