<?php

namespace App\Jobs;

use App\Models\Alert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendAlertEmail implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public int $alertId) {}

    public function handle(): void
    {
        $a = Alert::findOrFail($this->alertId);
        if ($a->emailed_at || ! $a->user->active) {
            return;
        }
        try {
            if (! config('tasksure.email_enabled') || in_array(config('mail.default'), ['log', 'array'])) {
                throw new \RuntimeException('External email transport is not configured.');
            }
            Mail::raw($a->message."\n".($a->task_id ? url('/tasks/'.$a->task_id) : url('/')), fn ($m) => $m->to($a->user->email)->subject('TaskSure: '.ucfirst(str_replace('_', ' ', $a->type))));
            $a->update(['email_status' => 'sent', 'emailed_at' => now(), 'delivery_error' => null]);
        } catch (\Throwable $e) {
            $a->update(['email_status' => 'failed', 'delivery_error' => 'Delivery failed; consult worker logs.']);
            throw $e;
        }
    }
}
