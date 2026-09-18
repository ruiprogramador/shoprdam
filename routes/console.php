<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:check-kyc-expired')->dailyAt('00:00');
Schedule::command('app:check-kyc-expiring-soon')->dailyAt('08:00');
Schedule::command('app:reconcile-orphaned-payment-attempts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('app:reconcile-orphaned-payout-attempts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
Schedule::command('app:prune-payment-provider-events')
    ->daily()
    ->withoutOverlapping()
    ->onOneServer();
// Phase 1 of feat/financial-reconciliation (docs/financial/RECONCILIATION.md)
// — detection + persistence + observability only, never a financial
// mutation. withoutOverlapping()->onOneServer() is an operational
// safeguard against wasted duplicate provider calls, not a correctness
// mechanism — see docs/financial/RECONCILIATION.md §11.1: no correctness
// invariant here depends on it, unlike the recovery commands above.
Schedule::command('app:reconcile-payments-against-provider')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
