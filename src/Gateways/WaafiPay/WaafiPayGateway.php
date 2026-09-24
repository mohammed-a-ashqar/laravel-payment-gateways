<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\WaafiPay;

use Alashqar\PaymentGateways\Contracts\AuthorizesPayments;
use Alashqar\PaymentGateways\Contracts\Gateway;
use Alashqar\PaymentGateways\Data\PaymentRequest;
use Alashqar\PaymentGateways\Data\PaymentResult;
use Alashqar\PaymentGateways\Data\RefundResult;
use Alashqar\PaymentGateways\Data\WebhookEvent;
use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Enums\RefundStatus;
use Alashqar\PaymentGateways\Exceptions\GatewayException;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Exceptions\UnsupportedOperation;
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
 * WaafiPay mobile-wallet payments through the "API" channel (POST /asm).
 *
 * Every call is a JSON envelope with a serviceName; success is signalled by
 * responseCode "2001" and anything else is treated as a failure, never as paid.
 *
 * @see https://docs.waafipay.com/api-introduction
 */
final readonly class WaafiPayGateway implements AuthorizesPayments, Gateway
{
    public const SANDBOX_URL = 'https://sandbox.waafipay.com/asm';

    public const LIVE_URL = 'https://api.waafipay.net/asm';

    private const SUCCESS = '2001';

    public function __construct(
        private GatewayClient $client,
        private WaafiPayCredentials $credentials,
        private SupportedCurrencies $currencies,
        private string $endpoint,
        private string $paymentMethod = 'MWALLET_ACCOUNT',
        private ?WaafiPaySignature $webhookSignature = null,
    ) {}

    public function name(): string
    {
        return 'waafipay';
    }

    /**
     * API_PURCHASE: the payer confirms on their handset and funds are debited at once.
     *
     * @see https://docs.waafipay.com/purchase-api
     */
    public function createPayment(PaymentRequest $request): PaymentResult
    {
        return $this->startPayment('API_PURCHASE', $request, PaymentStatus::Succeeded, withInvoiceId: true);
    }

    /**
     * API_PREAUTHORIZE: holds the funds until capture() or void().
     *
     * @see https://docs.waafipay.com/preauthorization-api
     */
    public function authorize(PaymentRequest $request): PaymentResult
    {
        return $this->startPayment('API_PREAUTHORIZE', $request, PaymentStatus::Authorized, withInvoiceId: false);
    }

    /**
     * API_PREAUTHORIZE_COMMIT: collects a previously authorized amount.
     */
    public function capture(string $transactionId): PaymentResult
    {
        $response = $this->call('API_PREAUTHORIZE_COMMIT', [
            'transactionId' => $transactionId,
            'description' => 'Commit',
        ]);

        return $this->toPaymentResult($response, PaymentStatus::Succeeded, fallbackId: $transactionId);
    }

    /**
     * API_PREAUTHORIZE_CANCEL: releases the hold. A rejected cancel throws, because the
     * hold's state is then unknown and must not be reported as released.
     */
    public function void(string $transactionId): PaymentResult
    {
        $response = $this->call('API_PREAUTHORIZE_CANCEL', [
            'transactionId' => $transactionId,
            'description' => 'Cancel',
        ]);

        if (! $this->succeeded($response)) {
            throw new GatewayException(
                "WaafiPay refused to cancel [{$transactionId}]: ".$this->failureReason($response),
                $this->name(),
                response: $response->all(),
            );
        }

        return new PaymentResult(PaymentStatus::Canceled, $transactionId, raw: $response->all());
    }

    /**
     * WaafiPay does not document a transaction inquiry for the API channel.
     */
    public function find(string $transactionId): PaymentResult
    {
        throw UnsupportedOperation::for($this->name(), 'transaction lookups');
    }

    /**
     * API_REVERSAL: reverses a completed purchase in full. WaafiPay documents it for
     * unsettled purchases (within 24 hours); partial refunds are not available.
     *
     * @see https://docs.waafipay.com/purchase-api
     */
    public function refund(string $transactionId, ?Money $amount = null, ?string $idempotencyKey = null): RefundResult
    {
        if ($amount !== null) {
            throw UnsupportedOperation::for($this->name(), 'partial refunds; call refund() without an amount');
        }

        $response = $this->call('API_REVERSAL', [
            'transactionId' => $transactionId,
            'description' => 'Refund',
        ]);

        $succeeded = $this->succeeded($response);

        return new RefundResult(
            status: $succeeded ? RefundStatus::Succeeded : RefundStatus::Failed,
            refundId: $response->string('params.transactionId'),
            transactionId: $transactionId,
            failureReason: $succeeded ? null : $this->failureReason($response),
            raw: $response->all(),
        );
    }

    /**
     * WaafiPay documents signed webhooks for Hosted Payment Page transactions.
     *
     * @see https://docs.waafipay.com/webhooks
     */
    public function parseWebhook(Request $request): WebhookEvent
    {
        if ($this->webhookSignature === null) {
            throw InvalidConfiguration::missing($this->name(), 'webhook_secret');
        }

        $body = $request->getContent();
        $eventId = $request->header('X-Webhook-Event-Id');

        $this->webhookSignature->verify(
            $body,
            $request->header('X-Webhook-Timestamp'),
            is_string($eventId) ? $eventId : null,
            $request->header('X-Webhook-Signature'),
            Carbon::now()->getTimestamp(),
        );

        return (new WaafiPayWebhookTranslator)->translate((string) $eventId, Payload::fromJson($body));
    }

    private function startPayment(string $service, PaymentRequest $request, PaymentStatus $onSuccess, bool $withInvoiceId): PaymentResult
    {
        $this->currencies->assertSupports($request->amount);

        $transactionInfo = array_filter([
            'referenceId' => $request->reference,
            'invoiceId' => $withInvoiceId ? ($request->metadata['invoice_id'] ?? $request->reference) : null,
            'amount' => $request->amount->toDecimal(),
            'currency' => $request->amount->currency,
            'description' => $request->description ?? $request->reference,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->call($service, [
            'paymentMethod' => $this->paymentMethod,
            'payerInfo' => ['accountNo' => $this->accountNumber($request)],
            'transactionInfo' => $transactionInfo,
        ]);

        return $this->toPaymentResult($response, $onSuccess, amount: $request->amount);
    }

    /**
     * @param  array<string, mixed>  $serviceParams
     */
    private function call(string $service, array $serviceParams): Payload
    {
        $envelope = [
            'schemaVersion' => '1.0',
            'requestId' => Str::uuid()->toString(),
            'timestamp' => (string) Carbon::now()->getTimestamp(),
            'channelName' => 'WEB',
            'serviceName' => $service,
            'serviceParams' => [...$this->credentials->toArray(), ...$serviceParams],
        ];

        $response = $this->client->send(
            fn (PendingRequest $http): Response => $http->asJson()->post($this->endpoint, $envelope)
        );

        $payload = Payload::fromResponse($response);

        if ($response->failed() || $payload->isEmpty()) {
            throw $this->client->failure($response, $payload->string('responseMsg') ?? 'unexpected response body');
        }

        return $payload;
    }

    private function succeeded(Payload $response): bool
    {
        return $response->string('responseCode') === self::SUCCESS;
    }

    /**
     * "2001" confirms the request was processed; the transaction state then says
     * whether money actually moved, so anything but APPROVED stays pending.
     */
    private function toPaymentResult(Payload $response, PaymentStatus $onSuccess, ?Money $amount = null, ?string $fallbackId = null): PaymentResult
    {
        $transactionId = $response->string('params.transactionId') ?? $fallbackId;

        if (! $this->succeeded($response)) {
            return new PaymentResult(
                status: PaymentStatus::Failed,
                transactionId: $transactionId,
                amount: $amount,
                failureReason: $this->failureReason($response),
                raw: $response->all(),
            );
        }

        $state = strtoupper($response->string('params.state') ?? '');

        return new PaymentResult(
            status: $state === 'APPROVED' ? $onSuccess : PaymentStatus::Pending,
            transactionId: $transactionId,
            amount: $amount,
            raw: $response->all(),
        );
    }

    private function failureReason(Payload $response): string
    {
        return trim(sprintf(
            '%s (responseCode %s, errorCode %s)',
            $response->string('responseMsg') ?? 'Unknown error',
            $response->string('responseCode') ?? '-',
            $response->string('errorCode') ?? '-',
        ));
    }

    /**
     * WaafiPay expects the full international number without "+" or leading zeros.
     */
    private function accountNumber(PaymentRequest $request): string
    {
        $phone = preg_replace('/[\s\-()]/', '', $request->customer->phone ?? '') ?? '';
        $phone = ltrim($phone, '+');

        if (preg_match('/^[1-9]\d{7,14}$/', $phone) !== 1) {
            throw new InvalidArgumentException(
                'WaafiPay needs the payer wallet number as customer phone in international format, e.g. 252611111111.'
            );
        }

        return $phone;
    }
}
