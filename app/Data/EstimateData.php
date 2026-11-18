<?php

namespace App\Data;

use App\Contracts\DataTransferObject;

final readonly class EstimateData implements DataTransferObject
{
    /** @param array<string, mixed> $pricing */
    public function __construct(
        public int $minimum,
        public int $maximum,
        public array $pricing,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'minimum' => (string) $this->minimum,
            'maximum' => (string) $this->maximum,
            'currency' => 'USD',
            'scale' => 12,
            'markup_percent' => 25,
        ];
    }
}
