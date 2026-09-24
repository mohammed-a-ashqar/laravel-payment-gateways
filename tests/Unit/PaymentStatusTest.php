<?php

declare(strict_types=1);

use Alashqar\PaymentGateways\Enums\PaymentStatus;
use Alashqar\PaymentGateways\Exceptions\InvalidStatusTransition;

it('allows the normal happy path', function () {
    $status = PaymentStatus::Pending
        ->transitionTo(PaymentStatus::RequiresAction)
        ->transitionTo(PaymentStatus::Authorized)
        ->transitionTo(PaymentStatus::Succeeded)
        ->transitionTo(PaymentStatus::PartiallyRefunded)
        ->transitionTo(PaymentStatus::PartiallyRefunded)
        ->transitionTo(PaymentStatus::Refunded);

    expect($status)->toBe(PaymentStatus::Refunded);
});

it('rejects transitions that would rewrite history', function (PaymentStatus $from, PaymentStatus $to) {
    expect($from->canTransitionTo($to))->toBeFalse();

    $from->transitionTo($to);
})->throws(InvalidStatusTransition::class)->with([
    'late failure after success' => [PaymentStatus::Succeeded, PaymentStatus::Failed],
    'success after failure' => [PaymentStatus::Failed, PaymentStatus::Succeeded],
    'refund before payment' => [PaymentStatus::Pending, PaymentStatus::Refunded],
    'back to pending after success' => [PaymentStatus::Succeeded, PaymentStatus::Pending],
    'revive a canceled payment' => [PaymentStatus::Canceled, PaymentStatus::Pending],
    'un-refund' => [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded],
]);

it('knows which statuses are final', function () {
    $final = array_values(array_filter(PaymentStatus::cases(), fn (PaymentStatus $s) => $s->isFinal()));

    expect($final)->toBe([PaymentStatus::Failed, PaymentStatus::Canceled, PaymentStatus::Refunded]);
});

it('only treats collected money as paid', function () {
    $paid = array_values(array_filter(PaymentStatus::cases(), fn (PaymentStatus $s) => $s->isPaid()));

    expect($paid)->toBe([PaymentStatus::Succeeded, PaymentStatus::PartiallyRefunded]);
});

it('describes the rejected transition', function () {
    PaymentStatus::Succeeded->transitionTo(PaymentStatus::Failed);
})->throws(InvalidStatusTransition::class, 'A payment cannot move from [succeeded] to [failed].');
