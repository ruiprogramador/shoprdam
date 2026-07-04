<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TransactionCategorySeeder extends Seeder
{
    public function run(): void
    { 
        /*
        |--------------------------------------------------------------------------
        | Transaction Categories
        |--------------------------------------------------------------------------
        |
        | These records are considered system data.
        | Never rename existing slugs after production deployment.
        |
        */
        $categories = [
            ['name' => 'Sale', 'slug' => 'sale', 'direction' => 'credit', 'description' => 'Income generated from a customer purchase'],
            ['name' => 'Customer Refund', 'slug' => 'customer_refund', 'direction' => 'debit', 'description' => 'Refund issued to customer'],
            ['name' => 'Commission Refund', 'slug' => 'commission_refund', 'direction' => 'credit', 'description' => 'Platform commission refunded to vendor'],
            ['name' => 'Withdrawal', 'slug' => 'withdrawal', 'direction' => 'debit', 'description' => 'Vendor withdraws funds to bank account'],
            ['name' => 'Commission', 'slug' => 'commission', 'direction' => 'debit', 'description' => 'Platform commission on a sale'],
            ['name' => 'Chargeback', 'slug' => 'chargeback', 'direction' => 'debit', 'description' => 'Payment disputed and reversed by customer\'s bank'],
            ['name' => 'Chargeback Reversal', 'slug' => 'chargeback_reversal', 'direction' => 'credit', 'description' => 'Chargeback reversed in favor of vendor'],
            ['name' => 'Refund Reversal', 'slug' => 'refund_reversal', 'direction' => 'credit', 'description' => 'Refund reversed in favor of vendor'],
            ['name' => 'Withdrawal Reversal', 'slug' => 'withdrawal_reversal', 'direction' => 'credit', 'description' => 'Withdrawal reversed due to failed transfer'],
            ['name' => 'Manual Credit', 'slug' => 'manual_credit', 'direction' => 'credit', 'description' => 'Manual adjustment by admin in vendor\'s favor'],
            ['name' => 'Manual Debit', 'slug' => 'manual_debit', 'direction' => 'debit', 'description' => 'Manual adjustment by admin against vendor'],
            ['name' => 'Bonus', 'slug' => 'bonus', 'direction' => 'credit', 'description' => 'Promotional bonus credited to vendor'],
            ['name' => 'Penalty', 'slug' => 'penalty', 'direction' => 'debit', 'description' => 'Penalty charged to vendor for policy violation'],
            ['name' => 'Subscription Fee', 'slug' => 'subscription_fee', 'direction' => 'debit', 'description' => 'Recurring subscription fee charged to vendor'],
            ['name' => 'Subscription Refund', 'slug' => 'subscription_refund', 'direction' => 'credit', 'description' => 'Refund of subscription fee to vendor'],
        ];

        foreach ($categories as $category) {
            DB::table('transaction_categories')->updateOrInsert(
                ['slug' => $category['slug']],
                array_merge($category, ['is_active' => true, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}