<?php

namespace Database\Factories;

use App\Models\Gender;
use App\Models\User;
use App\Models\Kyc;
use App\Models\KycStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

class KycFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'          => User::factory(),
            'kyc_status_id'    => KycStatus::where('slug', 'pending')->value('id'),
            'reviewed_by'      => null,
            'country_id'       => 177,
            'state_id'         => 3282,
            'city_id'          => 91876,
            'full_name'        => fake()->name(),
            'date_of_birth'    => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'gender_id'        => Gender::where('slug', 'male')->value('id'),
            'gender_other'     => null,
            'address_line1'    => fake()->streetAddress(),
            'address_line2'    => null,
            'postal_code'      => fake()->postcode(),
            'rejection_reason' => null,
            'reviewed_at'      => null,
            'expires_at'       => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'kyc_status_id' => KycStatus::where('slug', 'approved')->value('id'),
            'expires_at'    => now()->addYear(),
            'reviewed_at'   => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'kyc_status_id'    => KycStatus::where('slug', 'rejected')->value('id'),
            'rejection_reason' => fake()->sentence(),
            'reviewed_at'      => now(),
        ]);
    }

    public function expiringSoon(): static
    {
        return $this->state(fn () => [
            'kyc_status_id' => KycStatus::where('slug', 'approved')->value('id'),
            'expires_at'    => now()->addDays(15),
            'reviewed_at'   => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'kyc_status_id' => KycStatus::where('slug', 'expired')->value('id'),
            'expires_at'    => now()->subDay(),
            'reviewed_at'   => now(),
        ]);
    }
}