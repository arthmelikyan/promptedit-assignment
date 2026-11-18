<?php

namespace App\Http\Controllers;

use App\Contracts\GenerationProvider;
use App\Contracts\WebhookRepository;
use App\Http\Requests\PixelforgeWebhookRequest;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(PixelforgeWebhookRequest $request, WebhookRepository $events, GenerationProvider $provider): Response
    {
        $event = $events->receive($request->toData(), $provider->fingerprint());
        if ($event->processed_at === null) {
            try {
                ProcessWebhookJob::dispatch($event->id);
            } catch (Throwable $exception) {
                Log::warning('Webhook stored for scheduler recovery.', ['event_id' => $event->id, 'error' => $exception->getMessage()]);
            }
        }

        return response()->noContent();
    }
}
