<?php

use App\Providers\AppServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\TranslationServiceProvider;

return [
    AppServiceProvider::class,
    HorizonServiceProvider::class,
    EventServiceProvider::class,
    TranslationServiceProvider::class,
    PaymentServiceProvider::class,
];
