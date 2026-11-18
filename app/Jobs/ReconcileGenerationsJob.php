<?php

namespace App\Jobs;

use App\Actions\ReconcileGenerations;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcileGenerationsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 55;

    public int $uniqueFor = 90;

    public function __construct()
    {
        $this->onQueue('provider');
    }

    public function handle(ReconcileGenerations $action): void
    {
        $action->handle();
    }
}
