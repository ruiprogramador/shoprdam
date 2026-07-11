<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreFactory extends Factory
{
    protected $model = Store::class;

    public function definition(): array
    {
        return [
            'user_id'         => User::factory(),
            'store_status_id' => StoreStatus::bySlugOrFail('draft')->id,
            'name'            => fake()->company(),
            'phone'           => fake()->phoneNumber(),
            'email'           => fake()->companyEmail(),
            'short_description' => fake()->sentence(),
            'long_description'  => fake()->paragraph(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'store_status_id' => StoreStatus::bySlugOrFail('active')->id,
            'published_at'    => now(),
            'verified_at'     => now(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'store_status_id' => StoreStatus::bySlugOrFail('pending-review')->id
        ]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'store_status_id' => StoreStatus::bySlugOrFail('suspended')->id
        ]);
    }

    public function featured(): static
    {
        return $this->state(fn () => [
            'is_featured' => true,
        ]);
    }
}