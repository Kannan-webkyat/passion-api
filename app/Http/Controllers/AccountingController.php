<?php

namespace App\Http\Controllers;

use App\Services\Accounting\TrialBalanceService;
use App\Services\InventoryAuthorization;
use App\Services\KeralaComplianceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function gstr1Summary(Request $request, KeralaComplianceService $compliance)
    {
        $this->checkPermission('report-tax-gst-summary');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
        ]);

        return response()->json(
            $compliance->gstr1Summary(
                $validated['from'],
                $validated['to'],
                isset($validated['restaurant_id']) ? (int) $validated['restaurant_id'] : null,
            )
        );
    }

    public function kvatSummary(Request $request, KeralaComplianceService $compliance)
    {
        $this->checkPermission('report-tax-gst-summary');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        return response()->json($compliance->kvatSummary($validated['from'], $validated['to']));
    }

    public function gstr1Export(Request $request, KeralaComplianceService $compliance): StreamedResponse
    {
        $this->checkPermission('report-tax-gst-summary');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'restaurant_id' => 'nullable|integer|exists:restaurant_masters,id',
        ]);

        $rows = $compliance->gstr1ExportRows(
            $validated['from'],
            $validated['to'],
            isset($validated['restaurant_id']) ? (int) $validated['restaurant_id'] : null,
        );

        return $this->streamCsv(
            $rows,
            'gstr1-prep-'.$validated['from'].'-to-'.$validated['to'].'.csv'
        );
    }

    public function kvatExport(Request $request, KeralaComplianceService $compliance): StreamedResponse
    {
        $this->checkPermission('report-tax-gst-summary');

        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
        ]);

        $rows = $compliance->kvatExportRows($validated['from'], $validated['to']);

        return $this->streamCsv(
            $rows,
            'kvat-prep-'.$validated['from'].'-to-'.$validated['to'].'.csv'
        );
    }

    /** @param list<array<string, scalar|null>> $rows */
    private function streamCsv(array $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
