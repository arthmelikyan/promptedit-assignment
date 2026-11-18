<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class GenerationUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public string $queue = 'events';

    public function __construct(public int $userId, public string $generationId) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    /** @return array{generation_id: string} */
    public function broadcastWith(): array
    {
        return ['generation_id' => $this->generationId];
    }
}
