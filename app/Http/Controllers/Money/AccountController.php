<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\AccountRequest;
use App\Http\Requests\Money\ReconcileAccountRequest;
use App\Models\Money\Account;
use App\Services\Money\Ledger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(private Ledger $ledger) {}

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

    /**
     * Matches the account to the balance the bank shows, recording the gap as
     * an adjustment.
     */
    public function reconcile(ReconcileAccountRequest $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $this->ledger->reconcileAccount($account, $request->actualBalance());

        return back();
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->delete();

        return back();
    }
}
