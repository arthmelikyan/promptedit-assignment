<?php

namespace App\Contracts;

interface DataTransferObject
{
    /** @return array<string, mixed> */
    public function toArray(): array;
}
