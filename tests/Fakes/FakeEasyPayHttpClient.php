<?php

namespace Tests\Fakes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * EasyPay's counterpart to Tests\Fakes\FakeStripeHttpClient — EasyPay has no
 * official SDK to swap a client into (see EasyPayClient), so this installs
 * itself via Laravel's own Illuminate\Support\Facades\Http::fake() instead
 * of implementing an SDK interface.
 */
class FakeEasyPayHttpClient
{
    /**
     * Illuminate\Support\Facades\Http::fake() *adds* a stub callback rather
     * than replacing one — calling install() twice in the same test (e.g.
     * "fails, then recovers on retry") would otherwise leave the first
     * (failing) fake still registered and intercepting the second call.
     * Instead, one delegating closure is registered at most once per test's
     * actual Http Factory instance (tracked via $registeredOn — a fresh one
     * per test, since the whole application is rebuilt per test), and
     * every install() call just repoints $current to itself.
     */
    private static ?self $current = null;

    private static ?object $registeredOn = null;

    /** @var Request[] */
    public array $requests = [];

    /**
     * @param  array|null  $subsequentResponseBody  returned for every request after the first, if given —
     *                                              simulates EasyPay minting a different payment id on a second
     *                                              call, independent of whether its own Idempotency-Key dedup
     *                                              would actually prevent that in production.
     * @param  array<string, array>  $responsesById  keyed by a resource id whose GET retrieval request
     *                                               (.../single/{id} or .../refund/{id}) should get this
     *                                               response — takes priority over $responseBody/$subsequentResponseBody
     *                                               and is only ever matched against a GET request's trailing path
     *                                               segment, never a POST (create) call.
     * @param  \Throwable|null  $throws  thrown instead of returning a response — simulates a failure the real
     *                                   HTTP client raises itself (e.g. a connection reset), which never goes
     *                                   through an HTTP status code.
     */
    public function __construct(
        private readonly array $responseBody = [],
        private readonly int $responseCode = 200,
        private readonly ?array $subsequentResponseBody = null,
        private readonly array $responsesById = [],
        private readonly ?Throwable $throws = null,
    ) {}

    public function install(): static
    {
        self::$current = $this;

        $root = Http::getFacadeRoot();

        if (self::$registeredOn !== $root) {
            Http::fake(fn (Request $request) => self::$current->handle($request));
            self::$registeredOn = $root;
        }

        return $this;
    }

    private function handle(Request $request)
    {
        $this->requests[] = $request;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        if ($request->method() === 'GET') {
            $segments = explode('/', trim((string) parse_url($request->url(), PHP_URL_PATH), '/'));
            $id = end($segments);

            foreach ($this->responsesById as $candidateId => $body) {
                if ($id === (string) $candidateId) {
                    return Http::response($body, $this->responseCode);
                }
            }
        }

        $body = (count($this->requests) > 1 && $this->subsequentResponseBody !== null)
            ? $this->subsequentResponseBody
            : $this->responseBody;

        return Http::response($body, $this->responseCode);
    }

    public static function connectionFailure(string $message = 'Simulated network failure reaching EasyPay'): self
    {
        return new self(throws: new ConnectionException($message));
    }
}
