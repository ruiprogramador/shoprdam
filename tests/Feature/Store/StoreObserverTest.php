<?php

use App\Models\Store;

it('generates a unique slug when creating a store', function () {
    $store = Store::factory()->create([
        'name' => 'Minha Loja',
    ]);

    expect($store->slug)->toBe('minha-loja');
});

it('appends an incrementing suffix when slug already exists', function () {
    $store1 = Store::factory()->create(['name' => 'Minha Loja']);
    $store2 = Store::factory()->create(['name' => 'Minha Loja']);
    $store3 = Store::factory()->create(['name' => 'Minha Loja']);

    expect($store1->slug)->toBe('minha-loja')
        ->and($store2->slug)->toBe('minha-loja-1')
        ->and($store3->slug)->toBe('minha-loja-2');
});

it('respects a manually provided slug', function () {
    $store = Store::factory()->create([
        'name' => 'Outra Loja',
        'slug' => 'lojinha-personalizada',
    ]);

    expect($store->slug)->toBe('lojinha-personalizada');
});

it('creates a default EUR wallet when a store is created', function () {
    $store = Store::factory()->create();

    $store->refresh();

    expect($store->wallets)->toHaveCount(1);

    $wallet = $store->wallets->first();

    expect($wallet->store_id)
        ->toBe($store->id)
        ->and($wallet->currency->code)->toBe('EUR')
        ->and($wallet->balance)->toBe('0.00')
        ->and($wallet->last_transaction_at)->toBeNull();
});

it('does not create another wallet when the store is updated', function () {
    $store = Store::factory()->create();

    $walletId = $store->wallets()->first()->id;

    $store->update([
        'name' => 'Novo Nome',
    ]);

    $store->refresh();

    expect($store->wallets)
        ->toHaveCount(1)
        ->and($store->wallets->first()->id)
        ->toBe($walletId);
});

it('keeps soft deleted slugs reserved', function () {
    $store = Store::factory()->create([
        'name' => 'Minha Loja',
    ]);

    $store->delete();

    $newStore = Store::factory()->create([
        'name' => 'Minha Loja',
    ]);

    expect($newStore->slug)->toBe('minha-loja-1');
});