<?php

namespace App\Contracts;

use App\Data\ReportFilterData;

interface SpendRepository
{
    /** @return array<string, mixed> */
    public function report(ReportFilterData $filter, string $fingerprint): array;
}
