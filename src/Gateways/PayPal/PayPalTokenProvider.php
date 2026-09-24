<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\PayPal;

use Alashqar\PaymentGateways\Support\GatewayClient;
use Alashqar\PaymentGateways\Support\Payload;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * OAuth 2.0 client-credentials tokens, cached until shortly before they expire.
 *
 * PayPal tokens live for hours; fetching one per API call would double latency
 * and risk rate limits.
 *
 * @see https://developer.paypal.com/api/rest/authentication/
 */
final readonly class PayPalTokenProvider
{
    /** Refresh a little early so a token never expires mid-request. */
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private GatewayClient $client,
        private Cache $cache,
        private string $clientId,
        #[SensitiveParameter] private string $clientSecret,
        private string $baseUrl,
    ) {}

    public function token(): string
    {
        $cached = $this->cache->get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = $this->client->send(
            fn (PendingRequest $http): Response => $http
                ->withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post($this->baseUrl.'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
        );

        $payload = Payload::fromResponse($response);
        $token = $payload->string('access_token');

        if ($response->failed() || $token === null) {
            throw $this->client->failure($response, $payload->string('error_description') ?? 'could not obtain an access token');
        }

        $lifetime = $payload->int('expires_in') ?? 0;

        if ($lifetime > self::EXPIRY_MARGIN_SECONDS) {
            $this->cache->put($this->cacheKey(), $token, $lifetime - self::EXPIRY_MARGIN_SECONDS);
        }

        return $token;
    }

    public function forget(): void
    {
        $this->cache->forget($this->cacheKey());
    }

    /**
     * Keyed by environment and client id so sandbox and live tokens never mix.
     */
    private function cacheKey(): string
    {
        return 'payment-gateways:paypal:token:'.hash('sha256', $this->baseUrl.'|'.$this->clientId);
    }
}
