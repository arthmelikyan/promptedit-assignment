<?php

namespace App\Actions;

use App\Contracts\GenerationProvider;
use App\Contracts\GenerationRepository;
use App\Contracts\WebhookRepository;
use App\Enums\SubmissionState;
use App\Jobs\ProcessWebhookJob;
use App\Jobs\SubmitGenerationJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ReconcileGenerations
{
    public function __construct(
        private GenerationRepository $generations,
        private WebhookRepository $events,
        private GenerationProvider $provider,
        private ApplyGenerationUpdate $updates,
    ) {}

    public function handle(): void
    {
        $deadline = time() + 40;
        foreach ($this->events->pending() as $event) {
            ProcessWebhookJob::dispatch($event->id);
        }
        $pending = $this->generations->pending();
        $unknown = $pending->whereNull('provider_id')->whereIn('submission_state', [SubmissionState::Uncertain, SubmissionState::Submitting]);
        if ($unknown->isNotEmpty()) {
            $cursorKey = 'pixelforge.reconcile.cursor.'.$this->provider->fingerprint();
            $cursor = Cache::get($cursorKey);
            for ($page = 0; $page < 3 && time() < $deadline; $page++) {
                try {
                    $list = $this->provider->listing($cursor);
                    foreach ($list['data'] ?? [] as $object) {
                        $generation = $unknown->firstWhere('id', data_get($object, 'metadata.local_generation_id'));
                        if ($generation !== null && $generation->credential_fingerprint === $this->provider->fingerprint()) {
                            $this->updates->handle($generation->id, $object);
                        }
                    }
                    $cursor = $list['next_cursor'] ?? null;
                    Cache::put($cursorKey, $cursor, 3600);
                    if ($cursor === null || ! ($list['has_more'] ?? false)) {
                        break;
                    }
                } catch (Throwable $exception) {
                    Log::warning('Provider listing reconciliation delayed.', ['error' => $exception->getMessage()]);
                    break;
                }
            }
        }
        foreach ($pending as $generation) {
            if (time() >= $deadline) {
                break;
            }
            $generation = $this->generations->find($generation->id);
            if ($generation->status->isTerminal() || $generation->credential_fingerprint !== $this->provider->fingerprint()) {
                continue;
            }
            try {
                if ($generation->provider_id !== null) {
                    $this->updates->handle($generation->id, $this->provider->retrieve($generation->provider_id));
                } elseif ($generation->submission_attempts >= 5 && $generation->submission_state === SubmissionState::Pending) {
                    $this->updates->reject($generation->id, 'retries_exhausted', 'Submission retries were exhausted. You were not charged. Please try again.');
                } elseif ($generation->submission_attempts < 5 && ! $generation->next_attempt_at?->isFuture()) {
                    SubmitGenerationJob::dispatch($generation->id);
                }
            } catch (Throwable $exception) {
                Log::warning('Generation reconciliation delayed.', [
                    'generation_id' => $generation->id,
                    'error' => $exception->getMessage(),
                ]);
            }
            $this->generations->save($generation, ['last_checked_at' => now()]);
        }
    }
}
