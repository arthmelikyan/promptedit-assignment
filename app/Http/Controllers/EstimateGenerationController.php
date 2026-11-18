<?php

namespace App\Http\Controllers;

use App\Contracts\GenerationProvider;
use App\Exceptions\ProviderException;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Resources\EstimateResource;
use App\Services\GenerationPricing;
use Illuminate\Validation\ValidationException;

class EstimateGenerationController extends Controller
{
    public function __invoke(EstimateGenerationRequest $request, GenerationProvider $provider, GenerationPricing $pricing): EstimateResource
    {
        try {
            return new EstimateResource($pricing->estimate($request->toData(), $provider->pricing()));
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages(['pricing' => $exception->getMessage()]);
        }
    }
}
