<?php

namespace App\Contracts;

use App\Data\WebhookData;
use App\Models\WebhookEvent;
use Illuminate\Support\Collection;

interface WebhookRepository
{
    public function receive(WebhookData $data, string $fingerprint): WebhookEvent;

    /** @return Collection<int, WebhookEvent> */
    public function pending(): Collection;

    public function lock(int $id): WebhookEvent;

    public function markProcessed(WebhookEvent $event): void;

    public function recordFailure(int $eventId, string $message): void;
}
