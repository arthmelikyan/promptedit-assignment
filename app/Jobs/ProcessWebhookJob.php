<?php

namespace App\Jobs;

use App\Actions\ProcessWebhook;
use App\Contracts\WebhookRepository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessWebhookJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public int $uniqueFor = 120;

    public function __construct(public int $eventId)
    {
        $this->onQueue('events');
    }

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 15, 30, 60];
    }

    public function handle(ProcessWebhook $action): void
    {
        $action->handle($this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        app(WebhookRepository::class)->recordFailure($this->eventId, $exception?->getMessage() ?? 'Webhook processing failed.');
    }
}
