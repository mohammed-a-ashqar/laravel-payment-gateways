<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Exceptions;

/**
 * The gateway could not be reached or answered with a server error.
 *
 * The outcome of the operation is unknown: a charge may or may not have been
 * created. Retry with the same reference (idempotency key) or look the payment up
 * before trying again.
 */
final class GatewayUnavailable extends GatewayException {}
