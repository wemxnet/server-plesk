<?php

use App\Http\Middleware\RequireAdminReauthentication;
use Extensions\Servers\Plesk\Http\Controllers\Admin\LoginController as AdminLoginController;
use Extensions\Servers\Plesk\Http\Controllers\Client\LoginController as ClientLoginController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/orders/{order}/plesk/login', ClientLoginController::class)
        ->name('plesk.login');
});

Route::middleware(['web', 'auth', 'admin', RequireAdminReauthentication::class])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/orders/{order}/plesk/login', AdminLoginController::class)
            ->middleware('permission:admin.orders.view')
            ->name('plesk.login');
    });
