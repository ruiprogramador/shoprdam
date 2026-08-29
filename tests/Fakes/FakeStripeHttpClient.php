<?php

namespace Tests\Fakes;

use Stripe\HttpClient\ClientInterface;

/**
 * Metadata echo behavior for a create() response — see the `$metadataMode`
 * constructor parameter below.
 */
enum FakeStripeMetadataMode
{
    /**
     * Mirrors real Stripe behavior: a PaymentIntent create() response always
     * echoes back exactly the metadata it was sent, overwriting whatever
     * `$responseBody['metadata']` was configured with. Lets a fixture that
     * doesn't know the Order id yet (e.g. one exercising a command that
     * creates the Order itself) leave `metadata` unset and still get a
     * response whose metadata.order_id genuinely matches the request,
     * instead of having to hardcode a guessed id.
     */
    case AutoEchoRequestMetadata;

    /**
     * Returns exactly `$responseBody['metadata']` as configured — including
     * an empty array, or no `metadata` key at all — regardless of what
     * `metadata` the request sent. This is the only mode that can express
     * "Stripe responded with missing/wrong/corrupted metadata" even though
     * the request itself sent correct metadata; a test asserting
     * App\Domain\Payments\Services\PaymentService::assertResultMatchesAttempt() actually
     * rejects that must use this mode, since AutoEchoRequestMetadata would
     * silently paper over the very mismatch being tested.
     */
    case ReturnExactConfiguredMetadata;
}

class FakeStripeHttpClient implements ClientInterface
{
    public array $requests = [];

    /**
     * @param  array|null  $subsequentResponseBody  returned for every request after the first, if given.
     *                                              Used to simulate Stripe assigning a different PaymentIntent id
     *                                              on a second call, independent of whether Stripe's own
     *                                              idempotency-key dedup would actually prevent that in production.
     * @param  array<string, array>  $responsesById  keyed by a PaymentIntent id whose retrieve() request
     *                                               (`GET .../payment_intents/{id}`) should get this response —
     *                                               takes priority over $responseBody/$subsequentResponseBody.
     *                                               Matched against the URL's trailing path segment, not a raw
     *                                               substring, so it only ever answers that specific retrieve
     *                                               call and never a create() call or an unrelated id.
     * @param  \Throwable|null  $throws  thrown instead of returning a response — simulates a failure the real
     *                                   \Stripe\HttpClient\CurlClient raises itself (e.g. ApiConnectionException on a
     *                                   network failure), which never goes through an HTTP status code.
     * @param  FakeStripeMetadataMode  $metadataMode  see the enum above. Defaults to AutoEchoRequestMetadata to
     *                                                keep existing fixtures (which rely on the echo) working; any
     *                                                test that needs to assert behavior for missing/wrong metadata
     *                                                must explicitly pass ReturnExactConfiguredMetadata instead of
     *                                                relying on an `empty()` heuristic to infer intent.
     */
    public function __construct(
        private readonly array $responseBody = [],
        private readonly int $responseCode = 200,
        private readonly ?array $subsequentResponseBody = null,
        private readonly array $responsesById = [],
        private readonly ?\Throwable $throws = null,
        private readonly FakeStripeMetadataMode $metadataMode = FakeStripeMetadataMode::AutoEchoRequestMetadata,
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'headers', 'params');

        if ($this->throws !== null) {
            throw $this->throws;
        }

        foreach ($this->responsesById as $id => $body) {
            if (str_ends_with($absUrl, "/payment_intents/{$id}")) {
                return [json_encode($body), $this->responseCode, []];
            }
        }

        $body = (count($this->requests) > 1 && $this->subsequentResponseBody !== null)
            ? $this->subsequentResponseBody
            : $this->responseBody;

        if ($this->metadataMode === FakeStripeMetadataMode::AutoEchoRequestMetadata && ! empty($params['metadata'])) {
            $body['metadata'] = $params['metadata'];
        }

        return [json_encode($body), $this->responseCode, []];
    }
}
