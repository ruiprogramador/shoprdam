<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

/**
 * An Order has no customer/buyer identity of its own (see App\Models\Order)
 * — it only belongs to a Store. "Ownership" for authorization purposes is
 * therefore the vendor who owns that Store, the same relationship Wallet
 * crediting already trusts (see App\Domain\Payments\Services\PaymentService).
 */
class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    /** Whether $user may select a payment method and start/resume an attempt for $order. */
    public function pay(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    private function owns(User $user, Order $order): bool
    {
        return $user->isVendor() && $order->store->user_id === $user->id;
    }
}
