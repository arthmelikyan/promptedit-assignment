<?php

namespace App\Contracts;

use App\Exceptions\ProviderException;
use App\Models\Generation;

interface GenerationProvider
{
    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function pricing(bool $refresh = false): array;

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function submit(Generation $generation): array;

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function retrieve(string $providerId): array;

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function listing(?string $cursor = null): array;

    /**
     * @return array<string, mixed>
     *
     * @throws ProviderException
     */
    public function usage(string $startDate, string $endDate): array;

    public function fingerprint(): string;
}
