<?php

namespace Tests\Fakes;

use Stripe\HttpClient\ClientInterface;

class FakeStripeHttpClient implements ClientInterface
{
    public array $requests = [];

    public function __construct(
        private readonly array $responseBody,
        private readonly int $responseCode = 200,
    ) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = compact('method', 'absUrl', 'params');

        return [json_encode($this->responseBody), $this->responseCode, []];
    }
}
