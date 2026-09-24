<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for everything a gateway can throw, so callers can catch one type.
 */
class GatewayException extends RuntimeException
{
    /**
     * @param  array<array-key, mixed>  $response  Decoded gateway response body, when there was one.
     */
    public function __construct(
        string $message,
        public readonly string $gateway = '',
        public readonly ?int $httpStatus = null,
        public readonly array $response = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
