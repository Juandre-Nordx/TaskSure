<?php

namespace App\Providers;

use App\Contracts\WhatsAppGateway;
use App\Models\AccountAudit;
use App\Models\Attachment;
use App\Models\CompanyPolicyAcknowledgement;
use App\Models\MobileAccessToken;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\ActivityLogger;
use App\Services\ExistingActivityRecorder;
use App\Services\UnconfiguredWhatsApp;
use App\Support\StoreContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, UnconfiguredWhatsApp::class);
        $this->app->scoped(StoreContext::class);
    }

    public function boot(): void
    {
        AccountAudit::created(fn ($audit) => app(ExistingActivityRecorder::class)->account($audit));
        TaskActivity::created(fn ($activity) => app(ExistingActivityRecorder::class)->task($activity));
        CompanyPolicyAcknowledgement::created(fn ($ack) => app(ExistingActivityRecorder::class)->acknowledgement($ack));
        Attachment::deleted(function (Attachment $attachment) {
            $logger = app(ActivityLogger::class);
            $logger->write('evidence.deleted', $attachment, before: $logger->snapshot($attachment), origin: 'attachment_deleted:'.$attachment->id);
        });
        Task::updated(function (Task $task) {
            $fields = array_intersect(array_keys($task->getChanges()), ['title', 'instructions', 'area', 'category_id', 'priority', 'required_evidence', 'scheduled_at']);
            if ($fields) {
                $logger = app(ActivityLogger::class);
                $keys = array_flip($fields);
                $logger->write('task.edited', $task, array_intersect_key($logger->snapshot($task, true), $keys), array_intersect_key($logger->snapshot($task), $keys), ['changed_fields' => array_values($fields)]);
            }
        });
        Sanctum::usePersonalAccessTokenModel(MobileAccessToken::class);
        RateLimiter::for('login', function (Request $r) {
            $email = $r->input('email');
            $key = is_string($email) ? strtolower(mb_substr($email, 0, 180)) : '';

            return [Limit::perMinute(5)->by($key.'|'.$r->ip()), Limit::perMinute(30)->by($r->ip())];
        });
        RateLimiter::for('reset', fn (Request $r) => Limit::perMinute(3)->by($r->ip()));
        RateLimiter::for('mobile', fn (Request $r) => Limit::perMinute(120)->by($r->user()->id));
        RateLimiter::for('mobile-uploads', fn (Request $r) => Limit::perMinute(20)->by($r->user()->id));
        Paginator::useTailwind();
    }
}
