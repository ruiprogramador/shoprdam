<?php

namespace Database\Factories;

use App\Domain\Catalog\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Nnjeim\World\Models\Currency;
use RuntimeException;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $cents = fake()->numberBetween(100, 50000);

        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(3, true),
            // Integer cents formatted directly into an exact decimal string —
            // no float ever enters this calculation.
            'price_amount' => sprintf('%d.%02d', intdiv($cents, 100), $cents % 100),
            'currency_id' => Currency::query()->where('code', 'EUR')->value('id') ?? throw new RuntimeException('Currency EUR not found.'),
            'is_active' => true,
        ];
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn () => ['store_id' => $store->id]);
    }

    public function price(string $priceAmount): static
    {
        return $this->state(fn () => ['price_amount' => $priceAmount]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
