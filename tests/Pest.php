<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Tests\TestCase;
use Illuminate\Http\Client\Request;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

/**
 * Load a recorded-style gateway payload from tests/Fixtures.
 *
 * @return array<array-key, mixed>
 */
function fixture(string $path): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$path), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * The form-encoded body exactly as the gateway receives it (every scalar is a string).
 *
 * @return array<array-key, mixed>
 */
function formBody(Request $request): array
{
    parse_str($request->body(), $form);

    return $form;
}
