<?php

namespace App\Http\Middleware;

use App\Services\ActivityLogger;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class RecordActivityFailures
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (Throwable $error) {
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof AuthenticationException => 401,
                $error instanceof AuthorizationException => 403,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(), default => 500,
            };
            $this->record($request, $status);
            throw $error;
        }
        $status = $response->getStatusCode();
        $redirectPath = rtrim(parse_url($response->headers->get('Location', ''), PHP_URL_PATH) ?: '', '/');
        $redirectBlocked = $response->isRedirection() && in_array($redirectPath, ['/login', '/company-policy']);
        $validationFailed = $request->isMethod('POST') && $request->hasSession() && $request->session()->has('errors');
        $operationFailed = $request->attributes->get('activity_operation_failed');
        if ($status >= 400 || $validationFailed || $operationFailed || $redirectBlocked) {
            $logicalStatus = $status >= 400 ? $status : ($operationFailed ? 423 : ($validationFailed ? 422 : ($redirectPath === '/company-policy' ? 423 : 403)));
            $this->record($request, $logicalStatus, $status, $redirectBlocked && ! $validationFailed && ! $operationFailed);
        }

        return $response;
    }

    private function record(Request $request, int $status, ?int $responseStatus = null, bool $redirectOnly = false): void
    {
        if ($request->attributes->get('activity_failure_logged')) {
            return;
        }
        $route = $request->route();
        if (! $route) {
            return;
        }
        $handler = $route->getActionName();
        $action = match (true) {
            str_ends_with($handler, 'AuthController@login') => 'account.login',
            str_ends_with($handler, 'AuthController@logout') => 'account.logout',
            str_ends_with($handler, 'AdminController@saveUser') => $route->parameter('user') ? 'account.updated' : 'account.created',
            str_ends_with($handler, 'AdminController@membership') => 'account.store_access_added',
            str_ends_with($handler, 'SuperAdminController@save') => $route->parameter('store') ? 'store.updated' : 'store.created',
            str_ends_with($handler, 'SuperAdminController@ownerPassword') => 'account.password_changed',
            str_ends_with($handler, 'StoreController@saveSettings') => 'settings.company_changed',
            str_ends_with($handler, 'AdminController@category') => 'settings.category_created',
            str_ends_with($handler, 'AdminController@reminders') => 'settings.reminders_changed',
            str_ends_with($handler, 'AdminController@template'), str_ends_with($handler, 'TaskRecurrenceController@update') => 'task.schedule_edited',
            str_ends_with($handler, 'TaskRecurrenceController@make') => 'task.schedule_created',
            str_ends_with($handler, 'TaskRecurrenceController@status') => 'task.schedule_edited',
            str_ends_with($handler, 'TaskController@store') => 'task.created',
            str_ends_with($handler, 'TaskController@upload') => 'evidence.uploaded',
            str_ends_with($handler, 'TaskController@action') => ['start' => 'task.started', 'complete' => 'task.completed', 'submit' => 'task.completed', 'mark_reviewed' => 'task.reviewed', 'reopen' => 'task.reopened', 'reschedule' => 'task.deadline_changed', 'reassign' => 'task.assigned', 'cancel' => 'task.cancelled', 'comment' => 'task.comment_added', 'blocker' => 'task.blocker_added'][is_string($request->input('action')) ? $request->input('action') : ''] ?? 'task.edited',
            str_ends_with($handler, 'CompanyPolicyAdminController@publish') => 'policy.published',
            str_ends_with($handler, 'CompanyPolicyAdminController@store'), str_ends_with($handler, 'CompanyPolicyAdminController@draft') => $request->hasFile('policy_pdf') ? 'policy.uploaded' : 'policy.draft_saved',
            str_ends_with($handler, 'CompanyPolicyController@acknowledge') => 'policy.acknowledged',
            str_ends_with($handler, 'CompanyPolicyPdfController@published'), str_ends_with($handler, 'CompanyPolicyPdfController@draft') => $request->boolean('download') ? 'policy.downloaded' : 'policy.pdf_opened',
            str_contains($handler, 'ReportController') => match ($request->input('format')) {
                'pdf' => 'report.pdf_exported', 'xlsx' => 'report.xlsx_exported', default => 'report.generated'
            },
            default => null,
        };
        if (! $action) {
            return;
        }
        if ($redirectOnly && ($request->attributes->get('activity_request_succeeded') || isset($request->attributes->get('activity_success_actions', [])[$action]))) {
            return;
        }
        $reason = match ($status) {
            401 => 'authentication_required', 403 => 'access_denied', 404 => 'record_unavailable', 409 => 'state_changed', 419 => 'session_expired', 422 => 'validation_failed', 423 => 'policy_confirmation_required', 429 => 'rate_limited', default => 'operation_failed'
        };
        try {
            $logger = app(ActivityLogger::class);
            if ($action === 'account.login') {
                $logger->loginFailure($reason, $responseStatus ?? $status, $request->input('email'));

                return;
            }
            $type = match (strtok($action, '.')) {
                'account' => 'user', 'task' => 'task', 'evidence' => 'evidence', 'policy' => 'policy_version', 'report' => 'report', 'store' => 'store', default => 'settings'
            };
            $id = null;
            foreach (['task', 'store', 'user', 'template', 'version'] as $key) {
                $parameter = $route->parameter($key);
                if ($parameter !== null) {
                    $id = $parameter instanceof Model ? $parameter->getKey() : filter_var($parameter, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $type = ['template' => 'recurring_task', 'version' => 'policy_version'][$key] ?? $key;
                    break;
                }
            }
            if ($id === null && $type === 'policy_version') {
                $claimed = $request->input('policy_version_id');
                $id = is_scalar($claimed) ? (filter_var($claimed, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null) : null;
            }
            $logger->write($action, ['type' => $type, 'id' => $id ?: null, 'label' => 'Requested '.str_replace('_', ' ', $type).($id ? ' #'.$id : '')],
                details: ['http_status' => $responseStatus ?? $status, 'reason' => $reason], actor: $request->attributes->get('activity_actor'), result: 'failure');
        } catch (Throwable) {
            // A logging outage must not replace the original failure or expose its request/exception payload.
            logger()->error('Activity failure could not be recorded.', ['action' => $action]);
        }
    }
}
