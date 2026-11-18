<?php

namespace App\Http\Controllers;

use App\Actions\PurchaseCredits;
use App\Http\Requests\PurchaseCreditsRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PurchaseCreditsController extends Controller
{
    public function __invoke(PurchaseCreditsRequest $request, PurchaseCredits $action): RedirectResponse
    {
        $action->handle($request->user()->id, $request->toData());
        Inertia::flash('toast', ['type' => 'success', 'message' => '$10 in demo credits added. No real payment was made.']);

        return to_route('studio');
    }
}
