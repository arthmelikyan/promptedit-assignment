<?php

namespace App\Actions;

use App\Contracts\GenerationRepository;
use App\Contracts\WalletRepository;
use App\Enums\GenerationStatus;
use App\Enums\ReservationStatus;
use App\Enums\SubmissionState;
use App\Events\GenerationUpdated;
use App\Models\Generation;
use App\Services\GenerationPricing;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class ApplyGenerationUpdate
{
    public function __construct(
        private GenerationRepository $generations,
        private WalletRepository $wallets,
        private GenerationPricing $pricing,
    ) {}

    /** @param array<string, mixed> $object
     * @throws Throwable
     */
    public function handle(string $id, array $object): Generation
    {
        return DB::transaction(function () use ($id, $object): Generation {
            $generation = $this->generations->lock($id);
            $providerId = $object['id'] ?? null;
            $status = GenerationStatus::tryFrom((string) ($object['status'] ?? ''));
            if ($status === null || ! $this->matchesProviderId($generation, $providerId)) {
                throw new InvalidArgumentException('Provider generation identity or status mismatch.');
            }
            if (! $this->matchesInput($generation, $object)) {
                throw new InvalidArgumentException('Provider generation input mismatch.');
            }
            if ($generation->status->isTerminal()) {
                if ($status->isTerminal() && $generation->status !== $status) {
                    Log::error('Conflicting terminal provider status.', ['generation_id' => $id]);
                }

                return $generation;
            }
            if ($generation->status === GenerationStatus::Processing && $status === GenerationStatus::Queued) {
                return $generation;
            }
            $generation->fill([
                'provider_id' => $providerId,
                'submission_state' => SubmissionState::Accepted,
                'rates' => $object['rates'] ?? $generation->rates,
                'provider_created_at' => $object['created_at'] ?? $generation->provider_created_at,
                'started_at' => $object['started_at'] ?? $generation->started_at,
                'last_checked_at' => now(),
                'status' => $status,
                'error_code' => null,
                'error_message' => null,
            ]);

            if ($status === GenerationStatus::Succeeded) {
                $this->settle($generation, $object);
            } elseif ($status === GenerationStatus::Failed) {
                $this->releaseReservation($generation);
                $generation->completed_at = CarbonImmutable::parse($object['completed_at'] ?? now())->utc();
                $generation->error_code = (string) data_get($object, 'error.code', 'generation_failed');
                $generation->error_message = 'The provider could not generate this image. You were not charged. Please try again.';
            }
            $this->generations->save($generation);
            GenerationUpdated::dispatch($generation->user_id, $generation->id);

            return $generation;
        }, 3);
    }

    /**
     * @throws Throwable
     */
    public function reject(string $id, string $code, string $message): void
    {
        DB::transaction(function () use ($id, $code, $message): void {
            $generation = $this->generations->lock($id);
            if (! $generation->needsSubmission() || $generation->submission_state === SubmissionState::Uncertain) {
                return;
            }
            $this->releaseReservation($generation);
            $this->generations->save($generation, [
                'status' => GenerationStatus::Failed,
                'submission_state' => SubmissionState::Rejected,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
            ]);
            GenerationUpdated::dispatch($generation->user_id, $generation->id);
        }, 3);
    }

    private function matchesProviderId(Generation $generation, mixed $providerId): bool
    {
        return is_string($providerId)
            && str_starts_with($providerId, 'gen_')
            && (blank($generation->provider_id) || $generation->provider_id === $providerId);
    }

    /** @param array<string, mixed> $object */
    private function matchesInput(Generation $generation, array $object): bool
    {
        return ($object['prompt'] ?? null) === $generation->prompt
            && ($object['size'] ?? null) === $generation->settings['size']
            && ($object['quality'] ?? null) === $generation->settings['quality']
            && ($object['reference_images'] ?? []) === $generation->settings['reference_images'];
    }

    /** @param array<string, mixed> $object */
    private function settle(Generation $generation, array $object): void
    {
        if (! is_array($object['usage'] ?? null)
            || ! is_array($object['rates'] ?? null)
            || ! $this->isImageUrl($object['image_url'] ?? null)
            || empty($object['completed_at'])
        ) {
            throw new InvalidArgumentException('Successful generation is missing billing or output data.');
        }
        $cost = $this->pricing->cost($object['usage'], $object['rates']);
        $charge = $this->pricing->withMarkup($cost);
        $completedAt = CarbonImmutable::parse($object['completed_at'])->utc();
        $wallet = $this->wallets->lockForUser($generation->user_id);
        $reservation = $this->wallets->lockReservation($generation->id);
        if ($reservation->status !== ReservationStatus::Held) {
            throw new InvalidArgumentException('Generation reservation is not available for settlement.');
        }
        $this->generations->recordCharge($generation, $cost, $charge, $completedAt);
        $this->wallets->captureReservation($wallet, $reservation, $charge);
        $this->generations->storeImage($generation, $object['image_url'], $object['image_url_expires_at'] ?? null);
        $generation->usage = $object['usage'];
        $generation->completed_at = $completedAt;
        if ($charge > $reservation->amount) {
            Log::warning('Final charge exceeded the reserved estimate.', [
                'generation_id' => $generation->id,
                'deficit' => $wallet->balance < 0,
            ]);
        }
    }

    private function isImageUrl(mixed $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private function releaseReservation(Generation $generation): void
    {
        $wallet = $this->wallets->lockForUser($generation->user_id);
        $reservation = $this->wallets->lockReservation($generation->id);
        if ($reservation->status === ReservationStatus::Held) {
            $this->wallets->releaseReservation($wallet, $reservation);
        }
    }
}
