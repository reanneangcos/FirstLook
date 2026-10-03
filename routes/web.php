<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ScreeningController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/', [ScreeningController::class, 'dashboard'])->name('dashboard');
    Route::get('/screenings', [ScreeningController::class, 'index'])->name('screenings.index');
    Route::get('/screenings/create', [ScreeningController::class, 'create'])->name('screenings.create');
    Route::post('/screenings', [ScreeningController::class, 'store'])->middleware('throttle:10,1')->name('screenings.store');
    Route::get('/screenings/{screening}', [ScreeningController::class, 'show'])->name('screenings.show');
});
