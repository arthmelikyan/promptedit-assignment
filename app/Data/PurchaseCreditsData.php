<?php

namespace App\Data;

use App\Contracts\DataTransferObject;

final readonly class PurchaseCreditsData implements DataTransferObject
{
    public function __construct(public string $requestId) {}

    /** @return array{request_id: string} */
    public function toArray(): array
    {
        return ['request_id' => $this->requestId];
    }
}
