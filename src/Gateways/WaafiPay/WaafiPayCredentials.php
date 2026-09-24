<?php

declare(strict_types=1);

namespace Alashqar\PaymentGateways\Gateways\WaafiPay;

use SensitiveParameter;

final readonly class WaafiPayCredentials
{
    public function __construct(
        public string $merchantUid,
        public string $apiUserId,
        #[SensitiveParameter] private string $apiKey,
    ) {}

    /**
     * @return array{merchantUid: string, apiUserId: string, apiKey: string}
     */
    public function toArray(): array
    {
        return [
            'merchantUid' => $this->merchantUid,
            'apiUserId' => $this->apiUserId,
            'apiKey' => $this->apiKey,
        ];
    }
}
