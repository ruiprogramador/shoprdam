<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Kyc;

class KycPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Only vendors can interact with KYC.
     */
    private function isVendor(User $user): bool
    {
        return $user->isVendor();
    }

    /**
     * Determine if the vendor can view their own KYC.
     */
    public function view(User $user, Kyc $kyc): bool
    {
        return $this->isVendor($user) && $user->id === $kyc->user_id;
    }

    /**
     * Determine if the vendor can submit a new KYC.
     */
    public function create(User $user): bool
    {
        return $this->isVendor($user) && $user->canSubmitKyc();
    }

    /**
     * Determine if the vendor can update their KYC.
     */
    public function update(User $user, Kyc $kyc): bool
    {
        return $this->isVendor($user) && $user->id === $kyc->user_id && $kyc->canBeEdited();
    }

    /**
     * Determine if the vendor can delete their KYC.
     */
    public function delete(User $user, Kyc $kyc): bool
    {
        return $this->isVendor($user) && $user->id === $kyc->user_id && $kyc->canBeDeleted();
    }
}
