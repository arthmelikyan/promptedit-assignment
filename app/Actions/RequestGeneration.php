<?php

namespace App\Actions;

use App\Contracts\GenerationProvider;
use App\Contracts\GenerationRepository;
use App\Contracts\WalletRepository;
use App\Data\GenerationData;
use App\Jobs\SubmitGenerationJob;
use App\Models\Generation;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RequestGeneration
{
    public function __construct(
        private GenerationRepository $generations,
        private WalletRepository $wallets,
        private GenerationProvider $provider,
        private GenerationPricing $pricing,
    ) {}

    public function handle(int $userId, string $requestId, GenerationData $data): Generation
    {
        $existing = $this->generations->findRequest($userId, $requestId);
        if ($existing !== null) {
            return $this->sameRequest($existing, $data);
        }
        if (! config('services.pixelforge.key')) {
            throw ValidationException::withMessages(['prompt' => 'Pixelforge is not configured yet. Ask the operator to add an API key.']);
        }
        $estimate = $this->pricing->estimate($data, $this->provider->pricing());
        $generation = DB::transaction(function () use ($userId, $requestId, $data, $estimate): Generation {
            $wallet = $this->wallets->lockForUser($userId);
            $existing = $this->generations->findRequest($userId, $requestId);
            if ($existing !== null) {
                return $this->sameRequest($existing, $data);
            }
            if (! $wallet->canReserve($estimate->maximum)) {
                throw ValidationException::withMessages(['credits' => 'Not enough available credits for the maximum estimated charge. Buy credits or wait for ongoing generations to finish.']);
            }
            $generation = $this->generations->create([
                ...$data->toArray(),
                'user_id' => $userId,
                'request_id' => $requestId,
                'input_hash' => $data->fingerprint(),
                'credential_fingerprint' => $this->provider->fingerprint(),
                'estimated_min' => $estimate->minimum,
                'estimated_max' => $estimate->maximum,
                'pricing' => $estimate->pricing,
            ]);
            $this->wallets->createReservation($wallet, $generation->id, $estimate->maximum);

            return $generation;
        }, 3);
        rescue(function () use ($generation): void {
            SubmitGenerationJob::dispatch($generation->id);
        });

        return $generation;
    }

    private function sameRequest(Generation $generation, GenerationData $data): Generation
    {
        if (! hash_equals($generation->input_hash, $data->fingerprint())) {
            throw ValidationException::withMessages(['request_id' => 'This submission identifier was already used for different settings.']);
        }

        return $generation;
    }
}
