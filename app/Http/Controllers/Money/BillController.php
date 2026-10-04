<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\BillRequest;
use App\Models\Money\Bill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function store(BillRequest $request): RedirectResponse
    {
        $request->user()->bills()->create($request->billAttributes());

        return back();
    }

    public function update(BillRequest $request, Bill $bill): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->update($request->billAttributes());

        return back();
    }

    public function destroy(Request $request, Bill $bill): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->delete();

        return back();
    }
}
