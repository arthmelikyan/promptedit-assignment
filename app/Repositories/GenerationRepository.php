<?php

namespace App\Repositories;

use App\Contracts\GenerationRepository as GenerationRepositoryContract;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class GenerationRepository implements GenerationRepositoryContract
{
    public function findRequest(int $userId, string $requestId): ?Generation
    {
        return Generation::query()->where('user_id', $userId)->where('request_id', $requestId)->first();
    }

    public function find(string $id): Generation
    {
        return Generation::query()->findOrFail($id);
    }

    public function lock(string $id): Generation
    {
        return Generation::query()->lockForUpdate()->findOrFail($id);
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): Generation
    {
        return Generation::query()->create($attributes);
    }

    /** @return LengthAwarePaginator<int, Generation> */
    public function history(int $userId): LengthAwarePaginator
    {
        return Generation::query()
            ->where('user_id', $userId)
            ->with(['outputs', 'charge'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();
    }

    /** @return Collection<int, Generation> */
    public function pending(int $limit = 25): Collection
    {
        return Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subMinute()))
            ->orderBy('last_checked_at')
            ->limit($limit)
            ->get();
    }

    public function correlate(string $providerId, ?string $localId, string $fingerprint): ?Generation
    {
        return Generation::query()
            ->where('credential_fingerprint', $fingerprint)
            ->where(function ($query) use ($providerId, $localId): void {
                $query->where('provider_id', $providerId);
                if ($localId !== null) {
                    $query->orWhere('id', $localId);
                }
            })->first();
    }

    public function recordCharge(Generation $generation, int $cost, int $charge, CarbonImmutable $completedAt): void
    {
        $generation->charge()->create([
            'provider_cost' => $cost,
            'user_charge' => $charge,
            'completed_at' => $completedAt,
        ]);
    }

    public function storeImage(Generation $generation, string $url, ?string $expiresAt): void
    {
        $generation->outputs()->updateOrCreate(
            ['position' => 0],
            ['media_type' => 'image/png', 'url' => $url, 'expires_at' => $expiresAt],
        );
    }

    /** @param array<string, mixed> $attributes */
    public function save(Generation $generation, array $attributes = []): void
    {
        $generation->fill($attributes)->save();
    }

    public function countSubmissionAttempt(Generation $generation): void
    {
        $generation->increment('submission_attempts');
    }

    public function loadResult(Generation $generation): Generation
    {
        return $generation->load(['outputs', 'charge']);
    }
}
