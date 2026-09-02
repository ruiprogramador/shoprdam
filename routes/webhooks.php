<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use App\Payments\EasyPay\EasyPayWebhookController;
use App\Payments\Stripe\StripeWebhookController;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

Route::post('stripe/webhook', StripeWebhookController::class)
    ->name('stripe.webhook')
    ->withoutMiddleware([
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
        SetLocale::class,
    ]);

Route::post('easypay/webhook', EasyPayWebhookController::class)
    ->name('easypay.webhook')
    ->withoutMiddleware([
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
        SetLocale::class,
    ]);
