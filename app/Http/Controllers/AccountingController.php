<?php

namespace App\Http\Controllers;

use App\Services\Accounting\TrialBalanceService;
use App\Services\InventoryAuthorization;
use Illuminate\Http\Request;

class AccountingController extends Controller
{
    private function checkPermission(string $permission): void
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return;
        }
        if (
            $permission === InventoryAuthorization::TRIAL_BALANCE
            && InventoryAuthorization::canAny($user, [
                InventoryAuthorization::MANAGE,
                InventoryAuthorization::VENDOR_PAY,
            ])
        ) {
            return;
        }
        if (! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function trialBalance(Request $request, TrialBalanceService $trialBalance)
    {
        $this->checkPermission('accounting-view-trial-balance');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'include_zero' => 'nullable|boolean',
            'date_basis' => 'nullable|string|in:entry_date,business_date',
        ]);

        return response()->json(
            $trialBalance->forPeriod(
                $validated['from'],
                $validated['to'],
                (bool) ($validated['include_zero'] ?? false),
                $validated['date_basis'] ?? 'entry_date',
            )
        );
    }
}
