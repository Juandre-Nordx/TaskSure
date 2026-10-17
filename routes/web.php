<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyPolicyAdminController;
use App\Http\Controllers\CompanyPolicyController;
use App\Http\Controllers\CompanyPolicyPdfController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TaskRecurrenceController;
use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\RequireCompanyPolicy;
use App\Http\Middleware\ResolveStore;
use App\Http\Middleware\SuperAdmin;
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
Route::middleware(['auth', ActiveUser::class, SuperAdmin::class])->prefix('super-admin')->group(function () {
    Route::get('/', [SuperAdminController::class, 'index']);
    Route::get('/stores/create', [SuperAdminController::class, 'edit']);
    Route::get('/stores/{store}/edit', [SuperAdminController::class, 'edit']);
    Route::post('/stores', [SuperAdminController::class, 'save']);
    Route::post('/stores/{store}', [SuperAdminController::class, 'save']);
    Route::post('/stores/{store}/invite', [SuperAdminController::class, 'invite']);
    Route::post('/stores/{store}/owner-password', [SuperAdminController::class, 'ownerPassword']);
});
Route::middleware(['auth', ActiveUser::class, ResolveStore::class, RequireCompanyPolicy::class])->group(function () {
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index');
    Route::post('/stores/select', [StoreController::class, 'select'])->name('stores.select');
    Route::get('/admin/company-settings', [StoreController::class, 'settings']);
    Route::post('/admin/company-settings', [StoreController::class, 'saveSettings']);
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/company-policy', [CompanyPolicyController::class, 'show'])->name('company-policy.show');
    Route::post('/company-policy/acknowledge', [CompanyPolicyController::class, 'acknowledge'])->name('company-policy.acknowledge');
    Route::get('/company-policy/versions/{version}/pdf', [CompanyPolicyPdfController::class, 'published'])->whereNumber('version')->name('company-policy.pdf');
    Route::get('/admin/company-policy', [CompanyPolicyAdminController::class, 'index']);
    Route::post('/admin/company-policy/draft', [CompanyPolicyAdminController::class, 'draft']);
    Route::get('/admin/company-policy/draft/pdf', [CompanyPolicyPdfController::class, 'draft']);
    Route::post('/admin/company-policy/publish', [CompanyPolicyAdminController::class, 'publish']);
    Route::get('/admin/company-policy/versions/{version}', [CompanyPolicyAdminController::class, 'version'])->whereNumber('version');
    Route::get('/', [TaskController::class, 'dashboard'])->name('dashboard');
    Route::get('/tasks', [TaskController::class, 'index']);
    Route::get('/tasks/create', [TaskController::class, 'create']);
    Route::get('/recurring-tasks', [TaskRecurrenceController::class, 'index']);
    Route::post('/tasks/{task}/recurrence', [TaskRecurrenceController::class, 'make']);
    Route::post('/recurring-tasks/{template}/status', [TaskRecurrenceController::class, 'status']);
    Route::get('/reviews', [TaskController::class, 'reviews']);
    Route::post('/tasks', [TaskController::class, 'store']);
    Route::get('/tasks/{task}', [TaskController::class, 'show']);
    Route::post('/tasks/{task}/action', [TaskController::class, 'action']);
    Route::post('/tasks/{task}/uploads', [TaskController::class, 'upload']);
    Route::get('/attachments/{attachment}', [TaskController::class, 'attachment']);
    Route::get('/calendar', [TaskController::class, 'calendar']);
    Route::get('/calendar/events', [TaskController::class, 'events']);
    Route::get('/reports', ReportController::class);
    Route::get('/notifications', fn (Request $r) => view('notifications', ['alerts' => Alert::visibleTo($r->user())->latest()->paginate(20)]));
    Route::post('/notifications/{alert}/read', function (Alert $alert, Request $r) {
        abort_unless(Alert::visibleTo($r->user())->whereKey($alert->id)->exists(), 403);
        $alert->update(['read_at' => now()]);

        return back();
    });
    Route::post('/admin/memberships', [AdminController::class, 'membership']);
    Route::get('/admin/users', [AdminController::class, 'users']);
    Route::post('/admin/users', [AdminController::class, 'saveUser']);
    Route::post('/admin/users/{user}', [AdminController::class, 'saveUser']);
    Route::get('/admin/settings', [AdminController::class, 'settings']);
    Route::post('/admin/categories', [AdminController::class, 'category']);
    Route::post('/admin/reminders', [AdminController::class, 'reminders']);
    Route::post('/admin/templates', [AdminController::class, 'template']);
    Route::post('/admin/templates/{template}', [AdminController::class, 'template']);
});
