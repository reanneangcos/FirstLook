<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\PatientChatController;
use App\Http\Controllers\PatientRecordController;
use App\Http\Controllers\ScreeningController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PatientChatController::class, 'show'])->name('patient.chat');
Route::prefix('patient')->name('patient.')->group(function (): void {
    Route::post('/start', [PatientChatController::class, 'start'])->middleware('throttle:10,1,patient-start')->block(120, 10)->name('start');
    Route::post('/messages', [PatientChatController::class, 'store'])->middleware('throttle:60,1,patient-message')->block(120, 10)->name('messages');
    Route::post('/screen', [PatientChatController::class, 'screen'])->middleware('throttle:5,1,patient-screen')->block(120, 10)->name('screen');
    Route::post('/end', [PatientChatController::class, 'end'])->block(120, 10)->name('end');
});
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:5,1,staff-login');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::prefix('admin')->group(function (): void {
        Route::get('/', [ScreeningController::class, 'dashboard'])->name('dashboard');
        Route::get('/patients', [PatientRecordController::class, 'index'])->name('patients.index');
        Route::get('/patients/{patient}', [PatientRecordController::class, 'show'])->name('patients.show');
        Route::get('/screenings', [ScreeningController::class, 'index'])->name('screenings.index');
        Route::get('/screenings/create', [ScreeningController::class, 'create'])->name('screenings.create');
        Route::post('/screenings', [ScreeningController::class, 'store'])->middleware('throttle:10,1,staff-screen')->name('screenings.store');
        Route::get('/screenings/{screening}', [ScreeningController::class, 'show'])->name('screenings.show');
    });
    Route::redirect('/screenings', '/admin/screenings');
    Route::redirect('/screenings/create', '/admin/screenings/create');
    Route::get('/screenings/{screening}', fn (string $screening) => to_route('screenings.show', $screening));
});
