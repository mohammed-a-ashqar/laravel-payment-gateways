<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;

/**
 * Typed, null-safe access to decoded gateway JSON.
 *
 * Gateway responses are untrusted input: a field may be missing or have an
 * unexpected type, and that must never turn into a TypeError halfway through
 * recording a payment.
 */
final readonly class Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private array $data) {}

    public static function fromResponse(Response $response): self
    {
        $decoded = $response->json();

        return new self(is_array($decoded) ? $decoded : []);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        return new self(is_array($decoded) ? $decoded : []);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->data === [];
    }

    public function string(string $key): ?string
    {
        $value = Arr::get($this->data, $key);

        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => null,
        };
    }

    public function int(string $key): ?int
    {
        $value = Arr::get($this->data, $key);

        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => null,
        };
    }

    public function bool(string $key): ?bool
    {
        $value = Arr::get($this->data, $key);

        return is_bool($value) ? $value : null;
    }

    public function get(string $key): self
    {
        $value = Arr::get($this->data, $key);

        return new self(is_array($value) ? $value : []);
    }
}
