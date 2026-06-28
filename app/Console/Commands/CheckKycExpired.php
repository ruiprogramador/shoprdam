<?php

namespace App\Console\Commands;

use App\Events\KycExpired;
use App\Models\Kyc;
use App\Models\KycStatus;
use App\Models\Translation;
use Illuminate\Console\Command;

class CheckKycExpired extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-kyc-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark approved KYCs as expired and notify vendors';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $expiredStatus = KycStatus::findBySlug('expired');

        if (!$expiredStatus) {
            $this->error(Translation::getText('errors.kyc_status_not_found', 'en', ['slug' => 'expired'])); // Kyc status with slug :slug not found!
            return;
        }

        Kyc::approved()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->with('user')
            ->each(function (Kyc $kyc) use ($expiredStatus) {
                $kyc->transitionToStatus($expiredStatus);
                KycExpired::dispatch($kyc);
                $this->info(Translation::getText('log.kyc.expired', 'en', ['kyc_id' => $kyc->id, 'name' => $kyc->user->name, 'status' => $expiredStatus->name]));
            });
    }
}
