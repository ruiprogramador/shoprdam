<?php

namespace Tests\Fakes;

use Stripe\HttpClient\ClientInterface;

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
     */
    public function __construct(
        private readonly array $responseBody,
        private readonly int $responseCode = 200,
        private readonly ?array $subsequentResponseBody = null,
        private readonly array $responsesById = [],
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'headers', 'params');

        foreach ($this->responsesById as $id => $body) {
            if (str_ends_with($absUrl, "/payment_intents/{$id}")) {
                return [json_encode($body), $this->responseCode, []];
            }
        }

        $body = (count($this->requests) > 1 && $this->subsequentResponseBody !== null)
            ? $this->subsequentResponseBody
            : $this->responseBody;

        return [json_encode($body), $this->responseCode, []];
    }
}
