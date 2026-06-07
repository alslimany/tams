<?php

namespace App\DTOs\ESim;

readonly class ESimOrderResult
{
    public function __construct(
        public string $orderId,
        public string $iccid,
        public string $activationCode,
        public ?string $smdpAddress,
        public ?string $qrCodeUrl,
        public string $status,
        public bool $assigned = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'iccid' => $this->iccid,
            'activation_code' => $this->activationCode,
            'smdp_address' => $this->smdpAddress,
            'lpa_string' => $this->smdpAddress && $this->activationCode
                ? "LPA:1\${$this->smdpAddress}\${$this->activationCode}"
                : null,
            'qr_code_url' => $this->qrCodeUrl,
            'status' => $this->status,
            'assigned' => $this->assigned,
        ];
    }
}
