<?php

namespace App\Repositories;

use App\Contracts\WebhookRepository as WebhookRepositoryContract;
use App\Data\WebhookData;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;

final class WebhookRepository implements WebhookRepositoryContract
{
    public function receive(WebhookData $data, string $fingerprint): WebhookEvent
    {
        $payload = $data->toArray();

        return WebhookEvent::query()->firstOrCreate(['provider_event_id' => $payload['id']], [
            'type' => $payload['type'],
            'credential_fingerprint' => $fingerprint,
            'payload' => $payload,
        ]);
    }

    /** @return Collection<int, WebhookEvent> */
    public function pending(): Collection
    {
        return WebhookEvent::query()->whereNull('processed_at')->orderBy('id')->limit(25)->get();
    }

    public function lock(int $id): WebhookEvent
    {
        return WebhookEvent::query()->lockForUpdate()->findOrFail($id);
    }

    public function markProcessed(WebhookEvent $event): void
    {
        $event->update([
            'processed_at' => now(),
            'error' => null,
        ]);
    }

    public function recordFailure(int $eventId, string $message): void
    {
        WebhookEvent::query()->whereKey($eventId)->update([
            'error' => mb_substr($message, 0, 2000),
        ]);
    }
}
