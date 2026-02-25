<?php

use App\Http\Controllers\AgencyRegistrationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

Route::get('/register-agency', [AgencyRegistrationController::class, 'show'])->name('agency.register');
Route::post('/register-agency', [AgencyRegistrationController::class, 'store']);
