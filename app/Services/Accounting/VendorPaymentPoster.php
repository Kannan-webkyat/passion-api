<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\VendorPayment;

final class VendorPaymentPoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function post(VendorPayment $payment, ?int $postedBy = null): JournalEntry
    {
        $payment->loadMissing('purchaseOrder');

        $amount = round((float) $payment->amount, 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Vendor payment amount must be positive.');
        }

        $creditAccount = AccountCodes::tenderAccount($payment->payment_method);

        $entry = $this->journal->post(
            sourceType: 'vendor_payment',
            sourceId: (int) $payment->id,
            entryDate: $payment->paid_at->toDateString(),
            businessDate: null,
            sourceRef: $payment->purchaseOrder?->po_number,
            memo: 'Vendor payment — PO '.$payment->purchaseOrder?->po_number,
            lines: [
                [
                    'account_code' => AccountCodes::GRNI,
                    'debit' => $amount,
                    'meta' => [
                        'vendor_payment_id' => $payment->id,
                        'purchase_order_id' => $payment->purchase_order_id,
                        'vendor_id' => $payment->vendor_id,
                    ],
                ],
                [
                    'account_code' => $creditAccount,
                    'credit' => $amount,
                    'meta' => [
                        'vendor_payment_id' => $payment->id,
                        'payment_method' => $payment->payment_method,
                    ],
                ],
            ],
            postedBy: $postedBy ?? $payment->paid_by,
        );

        $payment->update(['journal_entry_id' => $entry->id]);

        return $entry;
    }
}
