<?php

use App\Http\Controllers\DevAdminController;
use App\Http\Controllers\DevAdminAuthController;
use App\Http\Middleware\EnsureDevAdminAccess;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return ['Laravel' => app()->version()];
});

Route::view('/test-app', 'test_app');

Route::prefix('dev-admin')->name('dev-admin.')->group(function () {
    Route::get('/login', [DevAdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [DevAdminAuthController::class, 'login'])->name('login.attempt');
    Route::post('/logout', [DevAdminAuthController::class, 'logout'])->name('logout');
});

Route::middleware([EnsureDevAdminAccess::class])->prefix('dev-admin')->name('dev-admin.')->group(function () {
    Route::get('/', [DevAdminController::class, 'index'])->name('index');
    Route::post('/sql', [DevAdminController::class, 'runSql'])->name('sql');

    Route::get('/tables/{table}', [DevAdminController::class, 'table'])
        ->where('table', '[A-Za-z0-9_]+')
        ->name('table');

    Route::get('/tables/{table}/create', [DevAdminController::class, 'create'])
        ->where('table', '[A-Za-z0-9_]+')
        ->name('create');
    Route::post('/tables/{table}', [DevAdminController::class, 'store'])
        ->where('table', '[A-Za-z0-9_]+')
        ->name('store');

    Route::get('/tables/{table}/{id}/edit', [DevAdminController::class, 'edit'])
        ->where('table', '[A-Za-z0-9_]+')
        ->name('edit');
    Route::put('/tables/{table}/{id}', [DevAdminController::class, 'update'])
        ->where('table', '[A-Za-z0-9_]+')
        ->name('update');
    Route::delete('/tables/{table}/{id}', [DevAdminController::class, 'destroy'])
        ->where('table', '[A-Za-z0-9_]+')
        ->name('destroy');
});

require __DIR__.'/auth.php';
