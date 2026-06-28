<?php

use App\Http\Controllers\Admin\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Admin\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Admin\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Admin\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Admin\Auth\NewPasswordController;
use App\Http\Controllers\Admin\Auth\PasswordController;
use App\Http\Controllers\Admin\Auth\PasswordResetLinkController;
use App\Http\Controllers\Admin\Auth\RegisteredUserController;
use App\Http\Controllers\Admin\Auth\VerifyEmailController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\KycController;
use App\Http\Controllers\Admin\TranslationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Middleware does not exist on surface level, exists on the core of the application
// https://laravel.com/docs/12.x/middleware
Route::middleware('guest:admin')
    ->prefix('admin')
    ->as('admin.')
    ->group(function () {

        Route::get('register', [RegisteredUserController::class, 'create'])
            ->name('register');

        Route::post('register', [RegisteredUserController::class, 'store']);

        Route::get('login', [AuthenticatedSessionController::class, 'create'])
            ->name('login');

        Route::post('login', [AuthenticatedSessionController::class, 'store']);

        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');

        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->name('password.store');
    });

Route::middleware('auth:admin')
    ->prefix('admin')
    ->as('admin.')
    ->group(function () {
        Route::get('verify-email', EmailVerificationPromptController::class)
            ->name('verification.notice');

        Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
            ->middleware(['signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('verification.send');

        Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
            ->name('password.confirm');

        Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])
            ->name('password.confirm.store');

        Route::put('password', [PasswordController::class, 'update'])->name('password.update');

        Route::get('dashboard', function () {
            //dd('admin dashboard');
            return Inertia::render('Admin/Dashboard/Index');
        })->name('dashboard');

        Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');

        // Admin Profile Routes
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        // Admin KYC Management Routes
        Route::prefix('kyc')->name('kyc.')->group(function () {
            Route::get('/export', [KycController::class, 'export'])->name('export');
            Route::get('/print', [KycController::class, 'print'])->name('print');
            Route::get('/', [KycController::class, 'index'])->name('index');
            Route::get('/{kyc}', [KycController::class, 'show'])->name('show');
            Route::post('/{kyc}/review', [KycController::class, 'review'])->name('review');
            // Route::post('/{kyc}/approve', [KycController::class, 'approve'])->name('approve');
            // Route::post('/{kyc}/reject', [KycController::class, 'reject'])->name('reject');
        });

        // Admin Translation Management Routes
        Route::prefix('translations')->name('translations.')->group(function () {
            Route::get('/',     [TranslationController::class, 'index'])->name('index');
            Route::get('/show', [TranslationController::class, 'show'])->name('show');
            Route::post('/',    [TranslationController::class, 'store'])->name('store');
            Route::delete('/{translation}', [TranslationController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('translation-values')->name('translations.')->group(function () {
            Route::match(['put', 'patch'], '/{translationValue}', [TranslationController::class, 'update'])->name('update');
        });

        Route::get('logs', [\Rap2hpoutre\LaravelLogViewer\LogViewerController::class, 'index']);

        Route::get('/preview/kyc-submitted', function () {
            $kyc = App\Models\Kyc::with('user')->first();
            $notification = new App\Notifications\KycSubmittedNotification($kyc);
            $mailMessage = $notification->toMail(new App\Models\Admin());
            return response($mailMessage->render());
        })->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])->name('preview.kyc-submitted');  

        Route::get('/preview/kyc-approved', function () {
            $kyc = App\Models\Kyc::with('user')->first();
            $kyc->expires_at = now()->addYear(); // fake para preview
            $notification = new App\Notifications\KycApprovedNotification($kyc);
            $mailMessage = $notification->toMail(new App\Models\Admin());
            return response($mailMessage->render());
        })->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])->name('preview.kyc-approved');

        Route::get('/preview/kyc-rejected', function () {
            $kyc = App\Models\Kyc::with('user')->first();
            $kyc->rejection_reason = 'Document is not clear'; // fake para preview
            $notification = new App\Notifications\KycRejectedNotification($kyc);
            $mailMessage = $notification->toMail(new App\Models\Admin());
            return response($mailMessage->render());
        })->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])->name('preview.kyc-rejected');

        Route::get('/preview/kyc-updated', function () {
            $kyc = App\Models\Kyc::with('user')->first();
            $notification = new App\Notifications\KycUpdatedNotification($kyc);
            $mailMessage = $notification->toMail(new App\Models\Admin());
            return response($mailMessage->render());
        })->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])->name('preview.kyc-updated');

        Route::get('/preview/kyc-expired', function () {
            $kyc = App\Models\Kyc::with('user')->first();
            $notification = new App\Notifications\KycExpiredNotification($kyc);
            $mailMessage = $notification->toMail(new App\Models\Admin());
            return response($mailMessage->render());
        })->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class])->name('preview.kyc-expired');
    });
