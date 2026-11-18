<?php

namespace App\Http\Controllers;

use App\Actions\RequestGeneration;
use App\Exceptions\ProviderException;
use App\Http\Requests\StoreGenerationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class StoreGenerationController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, RequestGeneration $action): RedirectResponse
    {
        try {
            $generation = $action->handle($request->user()->id, $request->requestId(), $request->toData());
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages(['prompt' => $exception->getMessage()]);
        }

        return to_route('studio', ['generation' => $generation->id]);
    }
}
