<?php

namespace App\Actions;

use App\Contracts\GenerationProvider;
use App\Contracts\GenerationRepository;
use App\Contracts\WalletRepository;
use App\Data\GenerationData;
use App\Enums\SubmissionState;
use App\Exceptions\ProviderException;
use App\Models\Generation;
use App\Services\GenerationPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class SubmitGeneration
{
    public function __construct(
        private GenerationRepository $generations,
        private GenerationProvider $provider,
        private ApplyGenerationUpdate $updates,
        private WalletRepository $wallets,
        private GenerationPricing $pricing,
    ) {}

    public function handle(string $id): ?int
    {
        $generation = $this->generations->find($id);
        if (! $generation->needsSubmission() || $generation->submission_attempts >= 5) {
            return null;
        }
        if ($generation->credential_fingerprint !== $this->provider->fingerprint()) {
            return null;
        }
        if ($generation->next_attempt_at?->isFuture()) {
            return max(1, (int) now()->diffInSeconds($generation->next_attempt_at));
        }
        $wasUncertain = in_array($generation->submission_state, [SubmissionState::Uncertain, SubmissionState::Submitting], true);
        $this->generations->countSubmissionAttempt($generation);
        try {
            if (! $wasUncertain && ! $this->reserveCurrentEstimate($generation)) {
                $this->updates->reject($id, 'insufficient_credits', 'Pricing changed before submission and available credits no longer cover it. You were not charged.');

                return null;
            }
            if (! $this->markSubmitting($id)) {
                return null;
            }
            $response = $this->provider->submit($generation);
            $this->updates->handle($id, $response);

            return null;
        } catch (ProviderException $exception) {
            $generation = $this->generations->find($generation->id);
            if (! $generation->needsSubmission()) {
                return null;
            }
            $uncertain = $exception->uncertain || $wasUncertain;
            if (! $exception->retryable && ! $uncertain) {
                $this->generations->save($generation, ['submission_state' => SubmissionState::Pending]);
                $this->updates->reject($id, $exception->providerCode, $exception->getMessage());

                return null;
            }
            $delay = max($exception->retryAfter, 2 ** $generation->submission_attempts) + random_int(0, 2);
            $this->generations->save($generation, [
                'submission_state' => $uncertain ? SubmissionState::Uncertain : SubmissionState::Pending,
                'error_code' => $exception->providerCode,
                'error_message' => $uncertain ? 'Provider confirmation is delayed. Your credits remain reserved while we check.' : 'The provider is temporarily unavailable. Retrying automatically.',
                'next_attempt_at' => now()->addSeconds($delay),
            ]);
            if ($generation->submission_attempts >= 5) {
                if (! $uncertain) {
                    $this->updates->reject($id, $exception->providerCode, 'Provider retries were exhausted. You were not charged. Please try again later.');
                }

                return null;
            }

            return $delay;
        } catch (Throwable $exception) {
            Log::error('Provider submission requires reconciliation.', [
                'generation_id' => $id,
                'error' => $exception->getMessage(),
            ]);
            $generation = $this->generations->find($generation->id);
            if (! $generation->status->isTerminal()) {
                $this->generations->save($generation, [
                    'submission_state' => SubmissionState::Uncertain,
                    'error_message' => 'Provider confirmation needs reconciliation. Your credits remain reserved.',
                    'next_attempt_at' => now()->addMinute(),
                ]);
            }

            return null;
        }
    }

    private function reserveCurrentEstimate(Generation $generation): bool
    {
        $input = new GenerationData(
            prompt: $generation->prompt,
            size: $generation->settings['size'],
            quality: $generation->settings['quality'],
            referenceImages: $generation->settings['reference_images'],
        );
        $estimate = $this->pricing->estimate($input, $this->provider->pricing(true));

        return DB::transaction(function () use ($generation, $estimate): bool {
            $locked = $this->generations->lock($generation->id);
            if (! $locked->needsSubmission()) {
                return false;
            }
            $wallet = $this->wallets->lockForUser($generation->user_id);
            $reservation = $this->wallets->lockReservation($generation->id);
            $extra = max(0, $estimate->maximum - $reservation->amount);
            if (! $wallet->canReserve($extra)) {
                return false;
            }
            $this->wallets->increaseReservation($wallet, $reservation, $extra);

            return true;
        }, 3);
    }

    private function markSubmitting(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $current = $this->generations->lock($id);
            if (! $current->needsSubmission()) {
                return false;
            }
            $this->generations->save($current, ['submission_state' => SubmissionState::Submitting]);

            return true;
        }, 3);
    }
}
