<?php

namespace App\Console\Commands;

use App\Events\KycExpiringSoon;
use App\Models\Kyc;
use App\Models\Translation;
use Illuminate\Console\Command;

class CheckKycExpiringSoon extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-kyc-expiring-soon';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify vendors whose KYC is expiring within 30 days';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Kyc::expiringSoon()
            ->with('user')
            ->each(function (Kyc $kyc) {
                KycExpiringSoon::dispatch($kyc);
                $this->info(Translation::getText('log.kyc.expiring_soon', 'en', ['user' => $kyc->user->name, 'expires_at' => $kyc->expires_at?->format('d/m/Y')])); // KYC for user :user is expiring on :expires_at
            });
    }
}
