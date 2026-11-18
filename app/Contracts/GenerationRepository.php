<?php

namespace App\Contracts;

use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface GenerationRepository
{
    public function findRequest(int $userId, string $requestId): ?Generation;

    public function find(string $id): Generation;

    public function lock(string $id): Generation;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Generation;

    /** @return LengthAwarePaginator<int, Generation> */
    public function history(int $userId): LengthAwarePaginator;

    /** @return Collection<int, Generation> */
    public function pending(int $limit = 25): Collection;

    public function correlate(string $providerId, ?string $localId, string $fingerprint): ?Generation;

    public function recordCharge(Generation $generation, int $cost, int $charge, CarbonImmutable $completedAt): void;

    public function storeImage(Generation $generation, string $url, ?string $expiresAt): void;

    /** @param array<string, mixed> $attributes */
    public function save(Generation $generation, array $attributes = []): void;

    public function countSubmissionAttempt(Generation $generation): void;

    public function loadResult(Generation $generation): Generation;
}
