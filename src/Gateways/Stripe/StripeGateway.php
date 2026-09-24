<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\Stripe;

use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Data\RefundResult;
use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\GatewayClient;
use Alashqar\PaymentGateways\Support\Payload;
use Alashqar\PaymentGateways\Support\SupportedCurrencies;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Stripe via hosted Checkout Sessions, spoken over the REST API directly.
 *
 * The transaction id is the Checkout Session id ("cs_..."); refunds resolve the
 * session's PaymentIntent automatically.
 *
 * @see https://docs.stripe.com/api/checkout/sessions/create
 */
final readonly class StripeGateway implements Gateway
{
    public function __construct(
        private GatewayClient $client,
        private string $secretKey,
        private SupportedCurrencies $currencies,
        private ?string $webhookSecret = null,
        private int $webhookTolerance = 300,
        private ?string $apiVersion = null,
        private string $baseUrl = 'https://api.stripe.com',
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $this->currencies->assertSupports($request->amount);

        if ($request->returnUrl === null) {
            throw new InvalidArgumentException('Stripe Checkout needs a returnUrl, which is used as the success_url.');
        }

        $metadata = [...$request->metadata, 'reference' => $request->reference];

        $params = array_filter([
            'mode' => 'payment',
            'success_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => $request->reference,
            'customer_email' => $request->customer?->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->amount->currency),
                    'unit_amount' => $request->amount->amount,
                    'product_data' => ['name' => $request->description ?? $request->reference],
                ],
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'description' => $request->description,
                'metadata' => $metadata,
            ]),
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->post('/v1/checkout/sessions', $params, 'checkout-session-'.$request->reference);

        return $this->toPaymentResult(Payload::fromResponse($response));
    }

    public function find(string $transactionId): PaymentResult
    {
        return $this->toPaymentResult($this->session($transactionId));
    }

    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult
    {
        $params = array_filter([
            'payment_intent' => $this->paymentIntentFor($transactionId),
            'amount' => $amount?->amount,
        ], fn (mixed $value): bool => $value !== null);

        $refund = Payload::fromResponse(
            $this->post('/v1/refunds', $params, $idempotencyKey ?? 'refund-'.Str::uuid()->toString())
        );

        $currency = $refund->string('currency');
        $refunded = $refund->int('amount');

        return new RefundResult(
            status: match ($refund->string('status')) {
                'succeeded' => RefundStatus::Succeeded,
                'failed', 'canceled' => RefundStatus::Failed,
                default => RefundStatus::Pending,
            },
            refundId: $refund->string('id'),
            transactionId: $transactionId,
            amount: $currency !== null && $refunded !== null ? Money::of($refunded, $currency) : null,
            failureReason: $refund->string('failure_reason'),
            raw: $refund->all(),
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        if ($this->webhookSecret === null) {
            throw InvalidConfiguration::missing($this->name(), 'webhook_secret');
        }

        $payload = $request->getContent();

        (new StripeSignature($this->webhookSecret, $this->webhookTolerance))
            ->verify($payload, $request->header('Stripe-Signature'), Carbon::now()->getTimestamp());

        return (new StripeWebhookTranslator)->translate(Payload::fromJson($payload));
    }

    private function session(string $sessionId): Payload
    {
        $response = $this->client->send(
            fn (PendingRequest $http): Response => $this->authenticate($http)
                ->get($this->baseUrl.'/v1/checkout/sessions/'.rawurlencode($sessionId))
        );

        return Payload::fromResponse($this->ensureSuccessful($response));
    }

    private function paymentIntentFor(string $transactionId): string
    {
        if (str_starts_with($transactionId, 'pi_')) {
            return $transactionId;
        }

        if (! str_starts_with($transactionId, 'cs_')) {
            throw new InvalidArgumentException('Stripe refunds need a Checkout Session (cs_...) or PaymentIntent (pi_...) id.');
        }

        $paymentIntent = $this->session($transactionId)->string('payment_intent');

        if ($paymentIntent === null) {
            throw new GatewayException("Checkout Session [{$transactionId}] has no payment to refund.", $this->name());
        }

        return $paymentIntent;
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function post(string $path, array $params, string $idempotencyKey): Response
    {
        $response = $this->client->send(
            fn (PendingRequest $http): Response => $this->authenticate($http)
                ->asForm()
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post($this->baseUrl.$path, $params)
        );

        return $this->ensureSuccessful($response);
    }

    private function authenticate(PendingRequest $http): PendingRequest
    {
        $http->withToken($this->secretKey);

        if ($this->apiVersion !== null) {
            $http->withHeaders(['Stripe-Version' => $this->apiVersion]);
        }

        return $http;
    }

    private function ensureSuccessful(Response $response): Response
    {
        if ($response->failed()) {
            throw $this->client->failure($response, Payload::fromResponse($response)->string('error.message'));
        }

        return $response;
    }

    private function toPaymentResult(Payload $session): PaymentResult
    {
        $status = StripeStatus::fromCheckoutSession(
            $session->string('status'),
            $session->string('payment_status'),
        );

        $total = $session->int('amount_total');
        $currency = $session->string('currency');

        return new PaymentResult(
            status: $status,
            transactionId: $session->string('id'),
            amount: $total !== null && $currency !== null ? Money::of($total, $currency) : null,
            redirectUrl: $status === PaymentStatus::RequiresAction ? $session->string('url') : null,
            raw: $session->all(),
        );
    }
}
