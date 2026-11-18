<?php

namespace App\Http\Controllers;

use App\Contracts\GenerationRepository;
use App\Contracts\WalletRepository;
use App\Http\Resources\GenerationResource;
use App\Http\Resources\WalletResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function __invoke(Request $request, GenerationRepository $generations, WalletRepository $wallets): Response
    {
        return Inertia::render('Studio', ['generations' => fn () => GenerationResource::collection($generations->history($request->user()->id)), 'wallet' => fn () => (new WalletResource($wallets->forUser($request->user()->id)))->resolve($request), 'providerConfigured' => (bool) config('services.pixelforge.key'), 'reverb' => ['key' => config('broadcasting.connections.reverb.key'), 'host' => config('services.pixelforge.reverb_public_host'), 'port' => config('services.pixelforge.reverb_public_port'), 'scheme' => config('services.pixelforge.reverb_public_scheme')]]);
    }
}
