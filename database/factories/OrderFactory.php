<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Nnjeim\World\Models\Currency;
use RuntimeException;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'currency_id' => Currency::query()->where('code', 'EUR')->value('id') ?? throw new RuntimeException('Currency EUR not found.'),
            'order_status_id' => OrderStatus::bySlugOrFail('pending')->id,
            'amount' => number_format(fake()->randomFloat(2, 5, 500), 2, '.', ''),
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn () => ['store_id' => $store->id]);
    }

    public function amount(string $amount): static
    {
        return $this->state(fn () => ['amount' => $amount]);
    }

    public function status(string $slug): static
    {
        return $this->state(fn () => ['order_status_id' => OrderStatus::bySlugOrFail($slug)->id]);
    }
}
