<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\StatementImportRequest;
use App\Services\Money\StatementImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

class StatementImportController extends Controller
{
    public function __construct(private StatementImport $import) {}

    /**
     * What importing this statement would do, row by row. Saves nothing.
     */
    public function preview(StatementImportRequest $request): JsonResponse
    {
        return response()->json($this->import->preview($request->user(), $request->card(), $request->string('csv')->toString()));
    }

    public function store(StatementImportRequest $request): RedirectResponse
    {
        $this->import->import($request->user(), $request->card(), $request->string('csv')->toString());

        return back();
    }
}
