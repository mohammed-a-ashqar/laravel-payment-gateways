<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Exceptions\GatewayUnavailable;
use Alashqar\PaymentGateways\Exceptions\InvalidConfiguration;
use Alashqar\PaymentGateways\Facades\Payments;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('payment-gateways.http.retry_delay_ms', 0);
});

it('retries connection failures and then succeeds', function () {
    Http::fake([
        'gateway.test/*' => Http::sequence()
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->push(['ok' => true]),
    ]);

    $response = Payments::client('acme')->send(fn (PendingRequest $http) => $http->post('https://gateway.test/charge'));

    expect($response->json('ok'))->toBeTrue();
    Http::assertSentCount(3);
});

it('gives up after the configured number of retries', function () {
    config()->set('payment-gateways.http.retries', 1);
    Http::fake(['gateway.test/*' => Http::failedConnection()]);

    try {
        Payments::client('acme')->send(fn (PendingRequest $http) => $http->post('https://gateway.test/charge'));
        $this->fail('Expected GatewayUnavailable.');
    } catch (GatewayUnavailable $exception) {
        expect($exception->gateway)->toBe('acme');
    }

    Http::assertSentCount(2);
});

it('never retries a server error because money may already have moved', function () {
    Http::fake(['gateway.test/*' => Http::response(['error' => 'boom'], 502)]);

    expect(fn () => Payments::client('acme')->send(fn (PendingRequest $http) => $http->post('https://gateway.test/charge')))
        ->toThrow(GatewayUnavailable::class, 'responded with HTTP 502');

    Http::assertSentCount(1);
});

it('returns client errors to the driver without retrying', function () {
    Http::fake(['gateway.test/*' => Http::response(['error' => 'bad card'], 402)]);

    $response = Payments::client('acme')->send(fn (PendingRequest $http) => $http->post('https://gateway.test/charge'));

    expect($response->status())->toBe(402);
    Http::assertSentCount(1);
});

it('applies per-gateway http overrides on top of the global defaults', function () {
    config()->set('payment-gateways.gateways.acme.http', ['timeout' => 90]);
    Http::fake(['gateway.test/*' => Http::response(['ok' => true])]);

    $request = Payments::client('acme')->request();

    expect($request->getOptions())->toMatchArray(['timeout' => 90, 'connect_timeout' => 10]);
});

it('rejects nonsensical http settings', function () {
    config()->set('payment-gateways.http.timeout', 'soon');

    Payments::client('acme');
})->throws(InvalidConfiguration::class, 'http.timeout');

it('sends json by default and accepts json', function () {
    Http::fake(['gateway.test/*' => Http::response(['ok' => true])]);

    Payments::client('acme')->send(fn (PendingRequest $http) => $http->post('https://gateway.test/charge', ['a' => 1]));

    Http::assertSent(fn (Request $request) => $request->hasHeader('Accept', 'application/json')
        && $request->data() === ['a' => 1]);
});
