<?php

use App\Http\Controllers\Api\MobileAuthController;
use App\Http\Controllers\Api\MobileNotificationController;
use App\Http\Controllers\Api\MobileTaskController;
use App\Http\Controllers\TaskController;
use App\Http\Middleware\MobileEmployee;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile/v1')->group(function () {
    Route::post('/login', [MobileAuthController::class, 'login'])->middleware('throttle:login');
    Route::middleware(['auth:sanctum', MobileEmployee::class, 'throttle:mobile'])->group(function () {
        Route::get('/me', [MobileAuthController::class, 'me']);
        Route::post('/logout', [MobileAuthController::class, 'logout']);
        Route::get('/tasks', [MobileTaskController::class, 'index']);
        Route::get('/tasks/{task}', [MobileTaskController::class, 'show']);
        Route::post('/tasks/{task}/actions', [TaskController::class, 'action']);
        Route::post('/tasks/{task}/uploads', [TaskController::class, 'upload'])->middleware('throttle:mobile-uploads');
        Route::get('/attachments/{attachment}', [TaskController::class, 'attachment']);
        Route::get('/calendar', [MobileTaskController::class, 'calendar']);
        Route::get('/notifications', [MobileNotificationController::class, 'index']);
        Route::post('/notifications/{alert}/read', [MobileNotificationController::class, 'read']);
        Route::post('/devices', [MobileNotificationController::class, 'register']);
        Route::delete('/devices', [MobileNotificationController::class, 'unregister']);
    });
});
