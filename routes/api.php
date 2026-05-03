<?php

use Illuminate\Support\Facades\Route;

use App\Models\State;
use App\Models\City;

Route::prefix('api')->name('api.')->group(function () {
    Route::get('/states', fn () => State::forCountry((int)request('country_id'))->get(['id', 'name']))->name('states');
    Route::get('/cities', fn () => City::forState((int)request('state_id'))->get(['id', 'name']))->name('cities');
}); 

require __DIR__ . '/auth.php';
require __DIR__ . '/admin.php';
