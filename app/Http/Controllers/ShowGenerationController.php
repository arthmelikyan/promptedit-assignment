<?php

namespace App\Http\Controllers;

use App\Contracts\GenerationRepository;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use Illuminate\Http\Request;

class ShowGenerationController extends Controller
{
    public function __invoke(Request $request, Generation $generation, GenerationRepository $generations): GenerationResource
    {
        abort_unless($request->user()->can('view', $generation), 404);

        return new GenerationResource($generations->loadResult($generation));
    }
}
