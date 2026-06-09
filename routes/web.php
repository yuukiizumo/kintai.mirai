<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AttendanceRecordController;
use App\Http\Controllers\AttendanceRequestController;
use App\Http\Controllers\AdminMessageController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\CalendarEntryController;
use App\Http\Controllers\SelfManagementReportController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\RetiredUserController;
use App\Http\Controllers\UserDisplayOrderController;
use App\Http\Controllers\UserWorkSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showUserLogin'])->name('login');
    Route::post('/login', fn (\Illuminate\Http\Request $request, AuthController $controller) => $controller->login($request, 'user'))
        ->name('login.store');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->name('register.store');

    Route::get('/admin/login', [AuthController::class, 'showAdminLogin'])->name('admin.login');
    Route::post('/admin/login', fn (\Illuminate\Http\Request $request, AuthController $controller) => $controller->login($request, 'admin'))
        ->name('admin.login.store');
    Route::get('/admin/register', [AuthController::class, 'showAdminRegister'])->name('admin.register');
    Route::post('/admin/register', [AuthController::class, 'registerAdmin'])->name('admin.register.store');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::get('/', function () {
    return view('app');
})->middleware('auth')->name('home');

Route::get('/attendance-history', function () {
    return view('app');
})->middleware('auth')->name('attendance-history');

Route::get('/business-report-history', function () {
    return view('app');
})->middleware('auth')->name('business-report-history');

Route::prefix('api')->middleware('auth')->group(function () {
    Route::get('/attendance-records', [AttendanceRecordController::class, 'index']);
    Route::get('/attendance-records/history', [AttendanceRecordController::class, 'history']);
    Route::get('/attendance-records/history/pdf', [AttendanceRecordController::class, 'historyPdf']);
    Route::get('/attendance-records/history/company-pdf', [AttendanceRecordController::class, 'historyCompanyPdf']);
    Route::get('/attendance-records/business-reports', [AttendanceRecordController::class, 'businessReports']);
    Route::get('/attendance-records/business-reports/pdf', [AttendanceRecordController::class, 'businessReportsPdf']);
    Route::post('/attendance-records', [AttendanceRecordController::class, 'store']);
    Route::put('/attendance-records/{attendanceRecord}', [AttendanceRecordController::class, 'update']);
    Route::delete('/attendance-records/{attendanceRecord}', [AttendanceRecordController::class, 'destroy']);
    Route::post('/attendance-records/clock', [AttendanceRecordController::class, 'clock']);
    Route::post('/attendance-records/clock/cancel', [AttendanceRecordController::class, 'cancelClock']);
    Route::get('/attendance-requests', [AttendanceRequestController::class, 'index']);
    Route::post('/attendance-requests', [AttendanceRequestController::class, 'store']);
    Route::patch('/attendance-requests/{attendanceRequest}/checks', [AttendanceRequestController::class, 'updateChecks']);
    Route::delete('/attendance-requests/{attendanceRequest}', [AttendanceRequestController::class, 'destroy']);
    Route::get('/admin-messages', [AdminMessageController::class, 'index']);
    Route::post('/admin-messages', [AdminMessageController::class, 'store']);
    Route::get('/admins', [AdminUserController::class, 'index']);
    Route::patch('/admins/{user}', [AdminUserController::class, 'update']);
    Route::get('/calendar-entries', [CalendarEntryController::class, 'index']);
    Route::post('/calendar-entries', [CalendarEntryController::class, 'store']);
    Route::delete('/calendar-entries/{calendarEntry}', [CalendarEntryController::class, 'destroy']);
    Route::get('/self-management-reports', [SelfManagementReportController::class, 'index']);
    Route::post('/self-management-reports', [SelfManagementReportController::class, 'store']);
    Route::patch('/self-management-reports/{selfManagementReport}', [SelfManagementReportController::class, 'update']);
    Route::get('/retired-users', [RetiredUserController::class, 'index']);
    Route::post('/users/{user}/retire', [RetiredUserController::class, 'retire']);
    Route::post('/users/{user}/restore', [RetiredUserController::class, 'restore']);
    Route::delete('/users/{user}/force-delete', [RetiredUserController::class, 'destroy']);
    Route::put('/users/display-order', [UserDisplayOrderController::class, 'update']);
    Route::put('/users/{user}/profile', [UserProfileController::class, 'update']);
    Route::put('/users/{user}/work-settings', [UserWorkSettingController::class, 'update']);
});
