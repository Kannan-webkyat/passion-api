<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\PosOrder;
use App\Models\PosPayment;

final class PosSettlePoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function isJournalRequired(PosOrder $order): bool
    {
        if ($order->is_complimentary || (float) $order->total_amount <= 0) {
            return false;
        }

        return round((float) $order->total_amount, 2) > 0;
    }

    public function postStrict(PosOrder $order, ?int $postedBy = null): ?JournalEntry
    {
        if ($this->isJournalRequired($order)) {
            LedgerPostingGuard::assertInfrastructure();
        }

        $entry = $this->post($order, $postedBy);

        if ($this->isJournalRequired($order)) {
            LedgerPostingGuard::requireEntry($entry, "pos_settle:{$order->id}");
        }

        return $entry;
    }

    public function post(PosOrder $order, ?int $postedBy = null): ?JournalEntry
    {
        $order->loadMissing('payments');

        if (! $this->isJournalRequired($order)) {
            return null;
        }

        $total = round((float) $order->total_amount, 2);
        if ($total <= 0) {
            return null;
        }

        $lines = [];

        foreach ($order->payments as $payment) {
            $amount = round((float) $payment->amount, 2);
            if ($amount <= 0) {
                continue;
            }

            $lines[] = [
                'account_code' => AccountCodes::tenderAccount($payment->method),
                'debit' => $amount,
                'meta' => [
                    'pos_order_id' => $order->id,
                    'pos_payment_id' => $payment->id,
                    'method' => $payment->method,
                ],
            ];
        }

        $discount = round((float) $order->discount_amount, 2);
        if ($discount > 0) {
            $lines[] = [
                'account_code' => AccountCodes::SALES_DISCOUNTS,
                'debit' => $discount,
                'meta' => ['pos_order_id' => $order->id],
            ];
        }

        $this->addCredit($lines, AccountCodes::RESTAURANT_SALES, (float) $order->gst_net_taxable, $order->id);
        $this->addCredit($lines, AccountCodes::BAR_SALES, (float) $order->vat_net_taxable, $order->id);
        $this->addCredit($lines, AccountCodes::OUTPUT_CGST, (float) $order->cgst_amount, $order->id, 'output_gst');
        $this->addCredit($lines, AccountCodes::OUTPUT_SGST, (float) $order->sgst_amount, $order->id, 'output_gst');
        $this->addCredit($lines, AccountCodes::OUTPUT_IGST, (float) $order->igst_amount, $order->id, 'output_gst');
        $this->addCredit($lines, AccountCodes::OUTPUT_VAT, (float) $order->vat_tax_amount, $order->id, 'output_vat');
        $this->addCredit($lines, AccountCodes::SERVICE_CHARGE, (float) $order->service_charge_amount, $order->id);
        $this->addCredit($lines, AccountCodes::DELIVERY_CHARGE, (float) $order->delivery_charge, $order->id);
        $this->addCredit($lines, AccountCodes::PACKING_CHARGE, (float) ($order->packing_charge ?? 0), $order->id);
        $this->addCredit($lines, AccountCodes::TIPS_PAYABLE, (float) $order->tip_amount, $order->id);

        $rounding = round((float) $order->rounding_amount, 2);
        if ($rounding > 0) {
            $this->addCredit($lines, AccountCodes::RESTAURANT_SALES, $rounding, $order->id);
        }

        // Inclusive liquor MRP (e.g. ₹770) can sit in vat_net_taxable while vat_tax_amount
        // / CGST+SGST are still 0. Cash is the full bill — plug the missing tax credit.
        $this->creditMissingTaxAndBalance($lines, $order);

        $entryDate = ($order->business_date ?? $order->closed_at ?? now())->toDateString();

        return $this->journal->post(
            sourceType: 'pos_settle',
            sourceId: (int) $order->id,
            entryDate: $entryDate,
            businessDate: $order->business_date?->toDateString(),
            sourceRef: 'POS #'.$order->id,
            memo: 'POS order settled',
            lines: $lines,
            postedBy: $postedBy,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function creditMissingTaxAndBalance(array &$lines, PosOrder $order): void
    {
        $headerTax = round((float) ($order->tax_amount ?? 0), 2);
        $splitTax = round(
            (float) ($order->cgst_amount ?? 0)
            + (float) ($order->sgst_amount ?? 0)
            + (float) ($order->igst_amount ?? 0)
            + (float) ($order->vat_tax_amount ?? 0),
            2
        );
        $unsplitTax = round($headerTax - $splitTax, 2);
        if ($unsplitTax >= 0.01) {
            $this->creditTaxRemainder($lines, $order, $unsplitTax);
        }

        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $line) {
            $debit += (float) ($line['debit'] ?? 0);
            $credit += (float) ($line['credit'] ?? 0);
        }
        $gap = round($debit - $credit, 2);
        if ($gap >= 0.01) {
            $this->creditTaxRemainder($lines, $order, $gap);
        }
    }

    /** @param list<array<string, mixed>> $lines */
    private function creditTaxRemainder(array &$lines, PosOrder $order, float $amount): void
    {
        $amount = round($amount, 2);
        if ($amount < 0.01) {
            return;
        }

        $vatNet = round((float) ($order->vat_net_taxable ?? 0), 2);
        $vatTax = round((float) ($order->vat_tax_amount ?? 0), 2);
        if ($vatNet > 0 || $vatTax > 0) {
            $this->addCredit($lines, AccountCodes::OUTPUT_VAT, $amount, $order->id, 'output_vat');

            return;
        }

        $igst = round((float) ($order->igst_amount ?? 0), 2);
        if ($igst > 0) {
            $this->addCredit($lines, AccountCodes::OUTPUT_IGST, $amount, $order->id, 'output_gst');

            return;
        }

        $half = round($amount / 2, 2);
        $this->addCredit($lines, AccountCodes::OUTPUT_CGST, $half, $order->id, 'output_gst');
        $this->addCredit($lines, AccountCodes::OUTPUT_SGST, round($amount - $half, 2), $order->id, 'output_gst');
    }

    /** @param list<array<string, mixed>> $lines */
    private function addCredit(array &$lines, string $code, float $amount, int $orderId, ?string $taxTag = null): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return;
        }

        $lines[] = [
            'account_code' => $code,
            'credit' => $amount,
            'tax_tag' => $taxTag,
            'meta' => ['pos_order_id' => $orderId],
        ];
    }
}
