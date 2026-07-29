<?php

namespace Database\Factories;

use App\Models\Store;
use App\Models\StoreWalletTransaction;
use App\Models\TransactionCategory;
use App\Models\TransactionStatus;
use App\Enums\TransactionSource;
use App\Models\StoreWallet;
use Illuminate\Database\Eloquent\Factories\Factory;

class StoreWalletTransactionFactory extends Factory
{
    protected $model = StoreWalletTransaction::class;

    public function definition(): array
    {
        $amount = number_format(fake()->randomFloat(2, 5, 500), 2, '.', '');

        return [
            'transaction_category_id' => TransactionCategory::bySlugOrFail('sale')->id,
            'transaction_status_id'   => TransactionStatus::bySlugOrFail('completed')->id,
            'amount'                  => $amount,
            'balance_after'           => $amount,
            'description'             => fake()->sentence(),
            'source'                  => TransactionSource::System,
        ];
    }

    public function category(string $slug): static
    {
        return $this->state(fn () => [
            'transaction_category_id' => TransactionCategory::bySlugOrFail($slug)->id,
        ]);
    }

    public function status(string $slug): static
    {
        return $this->state(fn () => [
            'transaction_status_id' => TransactionStatus::bySlugOrFail($slug)->id,
        ]);
    }

    public function pending(): static
    {
        return $this->status('pending');
    }

    public function failed(): static
    {
        return $this->status('failed');
    }

    public function reversed(): static
    {
        return $this->status('reversed');
    }

    public function completed(): static
    {
        return $this->status('completed');
    }

    public function sale(): static
    {
        return $this->category('sale');
    }

    public function customerRefund(): static
    {
        return $this->category('customer_refund');
    }

    public function withdrawal(): static
    {
        return $this->category('withdrawal');
    }

    public function commission(): static
    {
        return $this->category('commission');
    }

    public function manualCredit(): static
    {
        return $this->category('manual_credit');
    }

    public function manualDebit(): static
    {
        return $this->category('manual_debit');
    }

    public function amount(string $amount): static
    {
        return $this->state(fn () => [
            'amount'        => $amount,
            'balance_after' => $amount,
        ]);
    }

    public function forWallet(StoreWallet $wallet): static
    {
        return $this->for($wallet, 'storeWallet');
    }
}