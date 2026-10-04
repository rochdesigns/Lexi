<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\AccountRequest;
use App\Models\Money\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function store(AccountRequest $request): RedirectResponse
    {
        $request->user()->accounts()->create($request->accountAttributes());

        return back();
    }

    public function update(AccountRequest $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->update($request->accountAttributes());

        return back();
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->delete();

        return back();
    }
}
