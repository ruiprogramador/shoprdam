<?php

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\User\ProfileController;
use App\Http\Controllers\Vendor\KycController;

Route::get('/', function () {
    if (Auth::check()) {
        if (auth('web')->user()->userType->id === 2) {
            return redirect()->route('vendor.dashboard'); // Redirect to vendor dashboard if user type is vendor
        }else {
            return redirect()->route('dashboard'); // Redirect to user dashboard if user type is user
        }
    }

    return Inertia::render('Home', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        /* 'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION, */
    ]);
})->name('home');


// Routes for Terms of Service, Privacy Policy, and License
Route::prefix('legal')->group(function () {
    Route::get('terms-of-service', function () {
        return Inertia::render('Legal/TermsOfService');
    })->name('terms-of-service');

    Route::get('privacy-policy', function () {
        return Inertia::render('Legal/PrivacyPolicy');
    })->name('privacy-policy');

    Route::get('license', function () {
        return Inertia::render('Legal/License');
    })->name('license');
});

Route::middleware('auth:web')->group(function () {
    //Profile Routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth:web', 'role:user'])->group(function () {
    // Dashboard Route
    Route::get('/dashboard', [App\Http\Controllers\User\DashboardController::class, 'index'])->name('dashboard');
});

Route::middleware(['auth:web', 'role:vendor'])->group(function () {
    // Vendor Dashboard Route
    Route::get('/vendor/dashboard', [App\Http\Controllers\Vendor\DashboardController::class, 'index'])->name('vendor.dashboard');

    // Vendor KYC Routes
    Route::prefix('vendor/kyc')->name('vendor.kyc.')->group(function () {
        Route::get('/', [KYCController::class, 'index'])->name('index');
        Route::get('/create', [KYCController::class, 'create'])->name('create');
        Route::post('/', [KYCController::class, 'store'])->name('store');
        Route::get('/edit', [KYCController::class, 'edit'])->name('edit');
        Route::put('/', [KYCController::class, 'update'])->name('update');
    });

});

require __DIR__ . '/auth.php';
require __DIR__ . '/admin.php';
