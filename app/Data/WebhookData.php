<?php

namespace App\Data;

use App\Contracts\DataTransferObject;

final readonly class WebhookData implements DataTransferObject
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public array $payload,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->payload,
            'id' => $this->id,
            'type' => $this->type,
        ];
    }
}
