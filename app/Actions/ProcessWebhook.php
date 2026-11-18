<?php

namespace App\Actions;

use App\Contracts\GenerationRepository;
use App\Contracts\WebhookRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ProcessWebhook
{
    public function __construct(
        private WebhookRepository $events,
        private GenerationRepository $generations,
        private ApplyGenerationUpdate $updates,
    ) {}

    /**
     * @throws \Throwable
     */
    public function handle(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $event = $this->events->lock($id);
            if ($event->processed_at !== null) {
                return;
            }
            if ($event->type === 'ping' || ! in_array($event->type, ['generation.processing', 'generation.succeeded', 'generation.failed'], true)) {
                $this->events->markProcessed($event);

                return;
            }
            $object = $event->payload['data']['object'];
            if ($event->type !== 'generation.'.($object['status'] ?? '')) {
                throw new RuntimeException('Webhook type does not match generation status.');
            }
            $generation = $this->generations->correlate((string) $object['id'], data_get($object, 'metadata.local_generation_id'), $event->credential_fingerprint);
            if ($generation === null) {
                throw new RuntimeException('Webhook generation is not correlated yet.');
            }
            $this->updates->handle($generation->id, $object);
            $this->events->markProcessed($event);
        }, 3);
    }
}
