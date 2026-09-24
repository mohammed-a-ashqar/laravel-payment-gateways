<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Support;

use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * The single place where gateways talk HTTP, so timeouts, retries and error
 * translation behave identically for every driver.
 */
final readonly class GatewayClient
{
    public function __construct(
        private Factory $http,
        private string $gateway,
        private HttpOptions $options,
    ) {}

    /**
     * A pre-configured request. Only connection errors are retried: a 4xx will not
     * change on retry, and a 5xx on a non-idempotent call might already have moved money.
     */
    public function request(): PendingRequest
    {
        return $this->http
            ->acceptJson()
            ->timeout($this->options->timeout)
            ->connectTimeout($this->options->connectTimeout)
            ->retry(
                $this->options->retries + 1,
                $this->options->retryDelayMs,
                fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                throw: false,
            );
    }

    /**
     * Send a request and translate transport failures and 5xx responses into
     * GatewayUnavailable. 4xx responses are returned for the driver to interpret.
     *
     * @param  Closure(PendingRequest): Response  $send
     *
     * @throws GatewayUnavailable
     */
    public function send(Closure $send): Response
    {
        try {
            $response = $send($this->request());
        } catch (ConnectionException $exception) {
            throw new GatewayUnavailable(
                "Could not reach the [{$this->gateway}] gateway: {$exception->getMessage()}",
                $this->gateway,
                previous: $exception,
            );
        }

        if ($response->serverError()) {
            throw new GatewayUnavailable(
                "The [{$this->gateway}] gateway responded with HTTP {$response->status()}.",
                $this->gateway,
                $response->status(),
                Payload::fromResponse($response)->all(),
            );
        }

        return $response;
    }

    public function failure(Response $response, ?string $detail): GatewayException
    {
        $message = "The [{$this->gateway}] gateway rejected the request (HTTP {$response->status()})";

        return new GatewayException(
            $detail !== null && $detail !== '' ? "{$message}: {$detail}" : "{$message}.",
            $this->gateway,
            $response->status(),
            Payload::fromResponse($response)->all(),
        );
    }
}
