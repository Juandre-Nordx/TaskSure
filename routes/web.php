<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TaskController;
use App\Http\Middleware\ActiveUser;
use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::select('SELECT 1');
        $root = config('filesystems.disks.evidence.root');
        if (! is_dir($root) || ! is_writable($root)) {
            return response()->json(['status' => 'unhealthy'], 503);
        }

        return response()->json(['status' => 'ok']);
    } catch (Throwable $e) {
        report($e);

        return response()->json(['status' => 'unhealthy'], 503);
    }
});
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::view('/forgot-password', 'auth.forgot')->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:reset')->name('password.email');
    Route::get('/reset-password/{token}', fn (string $token, Request $r) => view('auth.reset', ['token' => $token, 'email' => $r->query('email')]))->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:reset')->name('password.update');
});
Route::middleware(['auth', ActiveUser::class])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/', [TaskController::class, 'dashboard'])->name('dashboard');
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/create', [TaskController::class, 'create']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::post('/tasks/{task}/action', [TaskController::class, 'action']);
    Route::post('/tasks/{task}/uploads', [TaskController::class, 'upload']);
    Route::get('/attachments/{attachment}', [TaskController::class, 'attachment']);
    Route::get('/calendar', [TaskController::class, 'calendar']);
    Route::get('/calendar/events', [TaskController::class, 'events']);
    Route::get('/reports', ReportController::class);
    Route::get('/notifications', fn (Request $r) => view('notifications', ['alerts' => Alert::where('user_id', $r->user()->id)->latest()->paginate(20)]));
    Route::post('/notifications/{alert}/read', function (Alert $alert, Request $r) {
        abort_unless($alert->user_id === $r->user()->id, 403);
        $alert->update(['read_at' => now()]);

        return back();
    });
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::post('/admin/users', [AdminController::class, 'saveUser']);
    Route::post('/admin/users/{user}', [AdminController::class, 'saveUser']);
    Route::get('/admin/settings', [AdminController::class, 'settings']);
    Route::post('/admin/categories', [AdminController::class, 'category']);
    Route::post('/admin/reminders', [AdminController::class, 'reminders']);
    Route::post('/admin/templates', [AdminController::class, 'template']);
    Route::post('/admin/templates/{template}', [AdminController::class, 'template']);
});
