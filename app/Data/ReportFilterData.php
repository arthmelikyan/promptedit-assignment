<?php

namespace App\Data;

use App\Contracts\DataTransferObject;

final readonly class ReportFilterData implements DataTransferObject
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $userId = null,
        public bool $refresh = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'user_id' => $this->userId,
        ];
    }
}
