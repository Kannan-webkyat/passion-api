<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\PosOrder;
use App\Models\PosOrderRefund;
use Illuminate\Support\Facades\Schema;

/**
 * Reverses revenue/tax credits proportionally when a POS refund is issued.
 */
final class PosRefundPoster
{
    public function __construct(
        private readonly JournalPostingService $journal,
    ) {}

    public function post(PosOrderRefund $refund, ?int $postedBy = null): ?JournalEntry
    {
        if (! Schema::hasTable('journal_entries')) {
            return null;
        }

        $refund->loadMissing('order');
        $order = $refund->order;
        if (! $order instanceof PosOrder) {
            return null;
        }

        $amount = round((float) $refund->amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $orderTotal = round((float) $order->total_amount, 2);
        if ($orderTotal <= 0) {
            return null;
        }

        $ratio = min(1.0, $amount / $orderTotal);
        $lines = [];

        $this->addDebitScaled($lines, AccountCodes::RESTAURANT_SALES, (float) $order->gst_net_taxable, $ratio, $order->id, $refund->id);
        $this->addDebitScaled($lines, AccountCodes::BAR_SALES, (float) $order->vat_net_taxable, $ratio, $order->id, $refund->id);
        $this->addDebitScaled($lines, AccountCodes::OUTPUT_CGST, (float) $order->cgst_amount, $ratio, $order->id, $refund->id, 'output_gst');
        $this->addDebitScaled($lines, AccountCodes::OUTPUT_SGST, (float) $order->sgst_amount, $ratio, $order->id, $refund->id, 'output_gst');
        $this->addDebitScaled($lines, AccountCodes::OUTPUT_IGST, (float) $order->igst_amount, $ratio, $order->id, $refund->id, 'output_gst');
        $this->addDebitScaled($lines, AccountCodes::OUTPUT_VAT, (float) $order->vat_tax_amount, $ratio, $order->id, $refund->id, 'output_vat');
        $this->addDebitScaled($lines, AccountCodes::SERVICE_CHARGE, (float) $order->service_charge_amount, $ratio, $order->id, $refund->id);
        $this->addDebitScaled($lines, AccountCodes::DELIVERY_CHARGE, (float) $order->delivery_charge, $ratio, $order->id, $refund->id);
        $this->addDebitScaled($lines, AccountCodes::PACKING_CHARGE, (float) ($order->packing_charge ?? 0), $ratio, $order->id, $refund->id);
        $this->addDebitScaled($lines, AccountCodes::TIPS_PAYABLE, (float) $order->tip_amount, $ratio, $order->id, $refund->id);

        $rounding = round((float) $order->rounding_amount, 2);
        if ($rounding > 0) {
            $this->addDebitScaled($lines, AccountCodes::RESTAURANT_SALES, $rounding, $ratio, $order->id, $refund->id);
        }

        $discount = round((float) $order->discount_amount, 2);
        if ($discount > 0) {
            $this->addCreditScaled($lines, AccountCodes::SALES_DISCOUNTS, $discount, $ratio, $order->id, $refund->id);
        }

        $debitTotal = round(array_sum(array_column(array_filter($lines, fn ($l) => ($l['debit'] ?? 0) > 0), 'debit')), 2);
        $creditTotal = round(array_sum(array_column(array_filter($lines, fn ($l) => ($l['credit'] ?? 0) > 0), 'credit')), 2);
        $tenderCredit = round(max(0.0, $debitTotal - $creditTotal), 2);
        if ($tenderCredit <= 0) {
            $tenderCredit = $amount;
        }

        $lines[] = [
            'account_code' => AccountCodes::tenderAccount((string) $refund->method),
            'credit' => $tenderCredit,
            'meta' => [
                'pos_order_id' => $order->id,
                'pos_order_refund_id' => $refund->id,
                'method' => $refund->method,
            ],
        ];

        $entryDate = ($refund->business_date ?? $refund->refunded_at ?? now())->toDateString();

        return $this->journal->post(
            sourceType: 'pos_refund',
            sourceId: (int) $refund->id,
            entryDate: $entryDate,
            businessDate: $refund->business_date?->toDateString(),
            sourceRef: 'POS refund #'.$refund->id,
            memo: 'POS refund — order #'.$order->id,
            lines: $lines,
            postedBy: $postedBy ?? $refund->refunded_by,
        );
    }

    /** @param list<array<string, mixed>> $lines */
    private function addDebitScaled(
        array &$lines,
        string $code,
        float $base,
        float $ratio,
        int $orderId,
        int $refundId,
        ?string $taxTag = null,
    ): void {
        $scaled = round($base * $ratio, 2);
        if ($scaled <= 0) {
            return;
        }

        $line = [
            'account_code' => $code,
            'debit' => $scaled,
            'meta' => ['pos_order_id' => $orderId, 'pos_order_refund_id' => $refundId],
        ];
        if ($taxTag) {
            $line['tax_tag'] = $taxTag;
        }
        $lines[] = $line;
    }

    /** @param list<array<string, mixed>> $lines */
    private function addCreditScaled(
        array &$lines,
        string $code,
        float $base,
        float $ratio,
        int $orderId,
        int $refundId,
    ): void {
        $scaled = round($base * $ratio, 2);
        if ($scaled <= 0) {
            return;
        }

        $lines[] = [
            'account_code' => $code,
            'credit' => $scaled,
            'meta' => ['pos_order_id' => $orderId, 'pos_order_refund_id' => $refundId],
        ];
    }
}
