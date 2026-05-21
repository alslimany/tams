<?php

namespace App\DTOs\ESim;

readonly class ESimOrderResult
{
    public function __construct(
        public string $orderId,
        public string $iccid,
        public string $activationCode,
        public ?string $qrCodeUrl,
        public string $status,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'iccid' => $this->iccid,
            'activation_code' => $this->activationCode,
            'qr_code_url' => $this->qrCodeUrl,
            'status' => $this->status,
        ];
    }
}
