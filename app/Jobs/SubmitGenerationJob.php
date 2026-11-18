<?php

namespace App\Jobs;

use App\Actions\SubmitGeneration;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SubmitGenerationJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 15;

    public int $timeout = 30;

    public int $uniqueFor = 90;

    public function __construct(public string $generationId)
    {
        $this->onQueue('provider');
    }

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('submit.'.$this->generationId))->releaseAfter(5)->expireAfter(45)];
    }

    public function handle(SubmitGeneration $action): void
    {
        $delay = $action->handle($this->generationId);

        if ($delay) {
            $this->release($delay);
        }
    }
}
