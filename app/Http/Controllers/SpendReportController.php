<?php

namespace App\Http\Controllers;

use App\Actions\BuildSpendReport;
use App\Http\Requests\SpendReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class SpendReportController extends Controller
{
    public function __invoke(SpendReportRequest $request, BuildSpendReport $action): Response
    {
        return Inertia::render('SpendReport', ['report' => fn () => $action->handle($request->toData())]);
    }
}
