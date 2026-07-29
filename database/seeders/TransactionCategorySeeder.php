<?php

/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
|
| These records are referenced throughout the application by their slug.
| Existing slugs must never be changed in production.
| Existing directions should also never be changed, as they affect wallet
| balance calculations.
| New categories may be added in future releases.
|
*/

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Enums\TransactionDirection;

class TransactionCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    { 
        $categories = [
            ['name' => 'Sale', 'slug' => 'sale', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 10, 'description' => 'Income generated from a customer purchase'],
            ['name' => 'Customer Refund', 'slug' => 'customer_refund', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 20, 'description' => 'Refund issued to customer'],
            ['name' => 'Commission Refund', 'slug' => 'commission_refund', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 30, 'description' => 'Platform commission refunded to vendor'],
            ['name' => 'Withdrawal', 'slug' => 'withdrawal', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 40, 'description' => 'Vendor withdraws funds to bank account'],
            ['name' => 'Commission', 'slug' => 'commission', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 50, 'description' => 'Platform commission on a sale'],
            ['name' => 'Chargeback', 'slug' => 'chargeback', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 60, 'description' => 'Payment disputed and reversed by customer\'s bank'],
            ['name' => 'Chargeback Reversal', 'slug' => 'chargeback_reversal', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 70, 'description' => 'Chargeback reversed in favor of vendor'],
            ['name' => 'Refund Reversal', 'slug' => 'refund_reversal', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 80, 'description' => 'Refund reversed in favor of vendor'],
            ['name' => 'Withdrawal Reversal', 'slug' => 'withdrawal_reversal', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 90, 'description' => 'Withdrawal reversed due to failed transfer'],
            ['name' => 'Manual Credit', 'slug' => 'manual_credit', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 100, 'description' => 'Manual adjustment by admin in vendor\'s favor'],
            ['name' => 'Manual Debit', 'slug' => 'manual_debit', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 110, 'description' => 'Manual adjustment by admin against vendor'],
            ['name' => 'Bonus', 'slug' => 'bonus', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 120, 'description' => 'Promotional bonus credited to vendor'],
            ['name' => 'Penalty', 'slug' => 'penalty', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 130, 'description' => 'Penalty charged to vendor for policy violation'],
            ['name' => 'Subscription Fee', 'slug' => 'subscription_fee', 'direction' => TransactionDirection::Debit->value, 'sort_order' => 140, 'description' => 'Recurring subscription fee charged to vendor'],
            ['name' => 'Subscription Refund', 'slug' => 'subscription_refund', 'direction' => TransactionDirection::Credit->value, 'sort_order' => 150, 'description' => 'Refund of subscription fee to vendor'],
        ];
        
        $timestamp = now();

        foreach ($categories as $category) {
            DB::table('transaction_categories')->updateOrInsert(
                ['slug' => $category['slug']],
                array_merge($category, ['is_active' => true, 'created_at' => $timestamp, 'updated_at' => $timestamp])
            );
        }
    }
}