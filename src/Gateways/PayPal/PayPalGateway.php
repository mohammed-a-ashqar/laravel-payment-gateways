<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\PayPal;

use Alashqar\PaymentGateways\Contracts\CapturesPayments;
use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Data\RefundResult;
use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\UnsupportedOperation;
use Alashqar\PaymentGateways\Money;
use Alashqar\PaymentGateways\Support\GatewayClient;
use Alashqar\PaymentGateways\Support\Payload;
use Alashqar\PaymentGateways\Support\SupportedCurrencies;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * PayPal Checkout through the Orders v2 API.
 *
 * Flow: createPayment() returns the order with a payer-action URL, the buyer
 * approves on PayPal and returns to your returnUrl, then capture() collects the
 * money. The transaction id is always the PayPal order id.
 *
 * @see https://developer.paypal.com/docs/api/orders/v2/
 */
final readonly class PayPalGateway implements CapturesPayments, Gateway
{
    public const SANDBOX_URL = 'https://api-m.sandbox.paypal.com';

    public const LIVE_URL = 'https://api-m.paypal.com';

    public function __construct(
        private GatewayClient $client,
        private PayPalTokenProvider $tokens,
        private SupportedCurrencies $currencies,
        private string $baseUrl,
        private ?string $brandName = null,
    ) {}

    public function name(): string
    {
        return 'paypal';
    }

    public function createPayment(PaymentRequest $request): PaymentResult
    {
        $this->currencies->assertSupports($request->amount);

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [array_filter([
                'reference_id' => $request->reference,
                'custom_id' => $request->reference,
                'invoice_id' => $request->reference,
                'description' => $request->description !== null ? Str::limit($request->description, 124) : null,
                'amount' => PayPalAmount::toPayPal($request->amount),
            ], fn (mixed $value): bool => $value !== null)],
        ];

        $experience = array_filter([
            'return_url' => $request->returnUrl,
            'cancel_url' => $request->cancelUrl,
            'brand_name' => $this->brandName,
            'user_action' => 'PAY_NOW',
        ], fn (mixed $value): bool => $value !== null);

        $body['payment_source'] = ['paypal' => array_filter([
            'email_address' => $request->customer?->email,
            'experience_context' => $experience,
        ], fn (mixed $value): bool => $value !== null)];

        $response = $this->ensureSuccessful($this->call(
            fn (PendingRequest $http): Response => $http
                ->withHeaders(['PayPal-Request-Id' => 'order-'.$request->reference])
                ->post($this->baseUrl.'/v2/checkout/orders', $body)
        ));

        return $this->toPaymentResult(Payload::fromResponse($response));
    }

    public function find(string $transactionId): PaymentResult
    {
        return $this->toPaymentResult($this->order($transactionId));
    }

    /**
     * Capture an approved order. A declined funding source is returned as a failed
     * result; capturing an already captured order returns its current state.
     */
    public function capture(string $transactionId): PaymentResult
    {
        $response = $this->call(
            fn (PendingRequest $http): Response => $http
                ->withHeaders(['PayPal-Request-Id' => 'capture-'.Str::uuid()->toString()])
                ->withBody('{}', 'application/json')
                ->post($this->baseUrl.'/v2/checkout/orders/'.rawurlencode($transactionId).'/capture')
        );

        if ($response->status() === 422) {
            $error = Payload::fromResponse($response);
            $issue = $error->string('details.0.issue');

            if ($issue === 'ORDER_ALREADY_CAPTURED') {
                return $this->find($transactionId);
            }

            return new PaymentResult(
                status: PaymentStatus::Failed,
                transactionId: $transactionId,
                failureReason: $issue ?? $error->string('message'),
                raw: $error->all(),
            );
        }

        return $this->toPaymentResult(Payload::fromResponse($this->ensureSuccessful($response)));
    }

    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult
    {
        $captureId = $this->order($transactionId)->string('purchase_units.0.payments.captures.0.id');

        if ($captureId === null) {
            throw new GatewayException("PayPal order [{$transactionId}] has no capture to refund.", $this->name());
        }

        $body = $amount === null ? '{}' : (string) json_encode(['amount' => PayPalAmount::toPayPal($amount)]);

        $response = $this->ensureSuccessful($this->call(
            fn (PendingRequest $http): Response => $http
                ->withHeaders(['PayPal-Request-Id' => $idempotencyKey ?? 'refund-'.Str::uuid()->toString()])
                ->withBody($body, 'application/json')
                ->post($this->baseUrl.'/v2/payments/captures/'.rawurlencode($captureId).'/refund')
        ));

        $refund = Payload::fromResponse($response);

        return new RefundResult(
            status: PayPalStatus::fromRefund($refund->string('status')),
            refundId: $refund->string('id'),
            transactionId: $transactionId,
            amount: PayPalAmount::fromPayPal($refund->get('amount')) ?? $amount,
            failureReason: $refund->string('status_details.reason'),
            raw: $refund->all(),
        );
    }

    public function parseWebhook(Request $request): WebhookEvent
    {
        throw UnsupportedOperation::for($this->name(), 'webhooks');
    }

    private function order(string $orderId): Payload
    {
        $response = $this->ensureSuccessful($this->call(
            fn (PendingRequest $http): Response => $http->get($this->baseUrl.'/v2/checkout/orders/'.rawurlencode($orderId))
        ));

        return Payload::fromResponse($response);
    }

    /**
     * Send an authenticated request, refreshing the token once if PayPal says it
     * is no longer valid (it may have been revoked before its advertised expiry).
     *
     * @param  Closure(PendingRequest): Response  $request
     */
    private function call(Closure $request): Response
    {
        $send = fn (): Response => $this->client->send(
            fn (PendingRequest $http): Response => $request(
                $http->withToken($this->tokens->token())->withHeaders(['Prefer' => 'return=representation'])
            )
        );

        $response = $send();

        if ($response->status() === 401) {
            $this->tokens->forget();
            $response = $send();
        }

        return $response;
    }

    private function ensureSuccessful(Response $response): Response
    {
        if ($response->failed()) {
            $error = Payload::fromResponse($response);

            throw $this->client->failure(
                $response,
                $error->string('details.0.description') ?? $error->string('message') ?? $error->string('error_description'),
            );
        }

        return $response;
    }

    private function toPaymentResult(Payload $order): PaymentResult
    {
        $status = PayPalStatus::fromOrder($order);

        return new PaymentResult(
            status: $status,
            transactionId: $order->string('id'),
            amount: PayPalAmount::fromPayPal($order->get('purchase_units.0.amount'))
                ?? PayPalAmount::fromPayPal($order->get('purchase_units.0.payments.captures.0.amount')),
            redirectUrl: $status === PaymentStatus::RequiresAction ? $this->approvalUrl($order) : null,
            raw: $order->all(),
        );
    }

    private function approvalUrl(Payload $order): ?string
    {
        $links = $order->all()['links'] ?? [];

        foreach (is_array($links) ? $links : [] as $link) {
            $link = new Payload(is_array($link) ? $link : []);

            if (in_array($link->string('rel'), ['payer-action', 'approve'], true)) {
                return $link->string('href');
            }
        }

        return null;
    }
}
