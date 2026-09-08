<?php

namespace App\Http\Controllers\Vendor;

use App\Domain\Payments\Exceptions\PaymentAlreadyResolvedException;
use App\Domain\Payments\PaymentMethodCatalog;
use App\Domain\Payments\Services\PaymentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vendor\SelectPaymentMethodRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets a vendor settle their own Order by picking a payment METHOD —
 * 'card', 'mbway', 'multibanco' — never a provider. PaymentMethodCatalog is
 * the only place that resolves a method to the provider backing it;
 * App\Domain\Payments\Services\PaymentService (see its own docblock)
 * remains the sole authority on whether a new PaymentAttempt may actually
 * be created for it — this controller never re-implements or bypasses that
 * gating, it only ever calls startAttempt() and reacts to the outcome.
 */
class OrderPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly PaymentMethodCatalog $catalog,
    ) {}

    public function show(Order $order): Response
    {
        $this->authorize('pay', $order);

        $order->load('currency', 'payment.currentAttempt');

        return Inertia::render('Vendor/Orders/Payment', [
            'order' => [
                'id' => $order->id,
                'amount' => (string) $order->amount,
                'currency' => $order->currency->code,
                'status' => $order->status?->slug,
            ],
            'methods' => $this->catalog->options(),
            'payment' => $this->presentPayment($order),
        ]);
    }

    public function store(SelectPaymentMethodRequest $request, Order $order): RedirectResponse
    {
        $this->authorize('pay', $order);

        $method = $request->validated('method');
        $provider = $this->catalog->providerFor($method);

        try {
            $this->paymentService->startAttempt($order, $provider, $method);
        } catch (PaymentAlreadyResolvedException) {
            return redirect()
                ->route('vendor.orders.payment.show', $order)
                ->with('error', 'This order has already been resolved and cannot be charged again.');
        }

        return redirect()
            ->route('vendor.orders.payment.show', $order)
            ->with('success', 'Payment started.');
    }

    /**
     * Never trusts frontend state to decide whether a new attempt could be
     * started — this is display-only. PaymentService::startAttempt() (via
     * createDurableAttempt()) is what actually enforces
     * PaymentAttemptStatus::blocksNewAttempt(); a stale/manipulated client
     * re-submitting while `is_blocking` is true just resumes the same
     * attempt instead of opening a second charge path.
     */
    private function presentPayment(Order $order): ?array
    {
        $payment = $order->payment;

        if ($payment === null) {
            return null;
        }

        $attempt = $payment->currentAttempt;

        return [
            'status' => $payment->status->value,
            'attempt' => $attempt === null ? null : [
                'method' => $attempt->method,
                'status' => $attempt->status->value,
                'is_blocking' => $attempt->status->blocksNewAttempt(),
                'is_terminal' => $attempt->status->isTerminal(),
            ],
        ];
    }
}
