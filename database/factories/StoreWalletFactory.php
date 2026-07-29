<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreWallet;
use Illuminate\Database\Eloquent\Factories\Factory;
use Nnjeim\World\Models\Currency;
use RuntimeException;

class StoreWalletFactory extends Factory
{
    protected $model = StoreWallet::class;

    public function definition(): array
    {
        return [
            'currency_id' => Currency::query()->where('code', 'EUR')->value('id') ?? throw new RuntimeException('Currency EUR not found.'),
            'balance'     => '0.00',
        ];
    }

    public function withBalance(string $amount): static
    {
        return $this->state(fn () => [
            'balance' => $amount,
        ]);
    }
}