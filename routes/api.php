<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileNotificationController;
use App\Http\Controllers\Api\MobileTaskController;
use App\Http\Controllers\CompanyPolicyController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskRecurrenceController;
use App\Http\Middleware\MobileEmployee;
use App\Http\Middleware\RequireCompanyPolicy;
use App\Http\Middleware\ResolveStore;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->group(function () {
    Route::post('/login', [MobileAuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', MobileEmployee::class, ResolveStore::class, RequireCompanyPolicy::class, 'throttle:mobile'])->group(function () {
        Route::get('/stores', [StoreController::class, 'index'])->name('mobile.stores.index');
        Route::post('/stores/select', [StoreController::class, 'select'])->name('mobile.stores.select');
        Route::get('/me', [MobileAuthController::class, 'me'])->name('mobile.me');
        Route::post('/logout', [MobileAuthController::class, 'logout'])->name('mobile.logout');
        Route::get('/company-policy', [CompanyPolicyController::class, 'show'])->name('mobile.policy.show');
        Route::post('/company-policy/acknowledge', [CompanyPolicyController::class, 'acknowledge'])->name('mobile.policy.acknowledge');
        Route::get('/tasks', [MobileTaskController::class, 'index']);
        Route::get('/team/tasks', [MobileTaskController::class, 'index'])->defaults('scope', 'team');
        Route::post('/team/tasks', [TaskController::class, 'store']);
        Route::get('/team/options', [MobileTaskController::class, 'options']);
        Route::get('/reviews', [MobileTaskController::class, 'index'])->defaults('scope', 'team')->name('mobile.reviews');
        Route::get('/tasks/{task}', [MobileTaskController::class, 'show']);
        Route::post('/tasks/{task}/actions', [TaskController::class, 'action']);
        Route::post('/tasks/{task}/recurrence', [TaskRecurrenceController::class, 'make']);
        Route::post('/recurring-tasks/{template}/status', [TaskRecurrenceController::class, 'status']);
        Route::post('/tasks/{task}/uploads', [TaskController::class, 'upload'])->middleware('throttle:mobile-uploads');
        Route::get('/attachments/{attachment}', [TaskController::class, 'attachment']);
        Route::get('/calendar', [MobileTaskController::class, 'calendar']);
        Route::get('/notifications', [MobileNotificationController::class, 'index']);
        Route::post('/notifications/{alert}/read', [MobileNotificationController::class, 'read']);
        Route::post('/devices', [MobileNotificationController::class, 'register']);
        Route::delete('/devices', [MobileNotificationController::class, 'unregister']);
    });
});
