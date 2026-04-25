<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Kyc;

class AdminKycPolicy
{
    /**
     * Create a new policy instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Determine if the admin can view any KYC.
     */
    public function viewAny(Admin $admin): bool
    {
        return true;
    }

    /**
     * Determine if the admin can view a specific KYC.
     */
    public function view(Admin $admin, Kyc $kyc): bool
    {
        return true;
    }

    /**
     * Determine if the admin can review (approve/reject) a KYC.
     */
    public function review(Admin $admin, Kyc $kyc): bool
    {
        return $kyc->canBeReviewed();
    }

    /**
     * Determine if the admin can approve a KYC.
     */
    public function approve(Admin $admin, Kyc $kyc): bool
    {
        return $kyc->canBeApproved();
    }

    /**
     * Determine if the admin can reject a KYC.
     */
    public function reject(Admin $admin, Kyc $kyc): bool
    {
        return $kyc->canBeRejected();
    }
}
