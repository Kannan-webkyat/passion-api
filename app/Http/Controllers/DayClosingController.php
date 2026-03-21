<?php

namespace App\Http\Controllers;

use App\Models\PosDayClosing;
use App\Models\PosOrder;
use App\Models\PosPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DayClosingController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    /**
     * Preview day closing summary for a restaurant and date.
     * GET /pos/day-closing/preview?restaurant_id=&date=YYYY-MM-DD
     */
    public function preview(Request $request)
    {
        $this->checkPermission('manage-restaurant');
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'date' => 'required|date',
        ]);

        $restaurantId = (int) $validated['restaurant_id'];
        $closedDate = $validated['date'];

        $existing = PosDayClosing::where('restaurant_id', $restaurantId)
            ->where('closed_date', $closedDate)
            ->first();

        $summary = $this->computeSummary($restaurantId, $closedDate);

        return response()->json([
            'already_closed' => (bool) $existing,
            'closing' => $existing?->load('closedByUser'),
            'summary' => $summary,
        ]);
    }

    /**
     * Perform day closing.
     * POST /pos/day-closing
     */
    public function close(Request $request)
    {
        $this->checkPermission('manage-restaurant');
        $validated = $request->validate([
            'restaurant_id' => 'required|exists:restaurant_masters,id',
            'date' => 'required|date',
            'opening_balance' => 'nullable|numeric',
            'closing_balance' => 'nullable|numeric',
            'notes' => 'nullable|string',
        ]);

        $restaurantId = (int) $validated['restaurant_id'];
        $closedDate = $validated['date'];

        $closing = DB::transaction(function () use ($restaurantId, $closedDate, $validated) {
            $summary = $this->computeSummary($restaurantId, $closedDate);

            $existing = PosDayClosing::where('restaurant_id', $restaurantId)
                ->where('closed_date', $closedDate)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $recloseNote = 'Re-closed at '.now()->format('d M Y H:i').' by '.(auth()->user()?->name ?? 'System');
                $notes = $existing->notes ? $existing->notes."\n\n".$recloseNote : $recloseNote;
                if (! empty($validated['notes'])) {
                    $notes = trim($validated['notes'])."\n\n".$notes;
                }

                $existing->update([
                    'closed_at' => now(),
                    'closed_by' => auth()->id(),
                    'opening_balance' => $validated['opening_balance'] ?? $existing->opening_balance,
                    'closing_balance' => $validated['closing_balance'] ?? $existing->closing_balance,
                    'total_sales' => $summary['total_sales'],
                    'total_discount' => $summary['total_discount'],
                    'total_tax' => $summary['total_tax'],
                    'total_service_charge' => $summary['total_service_charge'],
                    'total_tip' => $summary['total_tip'],
                    'total_paid' => $summary['total_paid'],
                    'cash_total' => $summary['cash_total'],
                    'card_total' => $summary['card_total'],
                    'upi_total' => $summary['upi_total'],
                    'room_charge_total' => $summary['room_charge_total'],
                    'order_count' => $summary['order_count'],
                    'void_count' => $summary['void_count'],
                    'notes' => $notes ?: null,
                ]);

                return $existing;
            }

            return PosDayClosing::create([
                'restaurant_id' => $restaurantId,
                'closed_date' => $closedDate,
                'closed_at' => now(),
                'closed_by' => auth()->id(),
                'opening_balance' => $validated['opening_balance'] ?? null,
                'closing_balance' => $validated['closing_balance'] ?? null,
                'total_sales' => $summary['total_sales'],
                'total_discount' => $summary['total_discount'],
                'total_tax' => $summary['total_tax'],
                'total_service_charge' => $summary['total_service_charge'],
                'total_tip' => $summary['total_tip'],
                'total_paid' => $summary['total_paid'],
                'cash_total' => $summary['cash_total'],
                'card_total' => $summary['card_total'],
                'upi_total' => $summary['upi_total'],
                'room_charge_total' => $summary['room_charge_total'],
                'order_count' => $summary['order_count'],
                'void_count' => $summary['void_count'],
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return response()->json([
            'message' => 'Day closed successfully.',
            'closing' => $closing->load('closedByUser'),
        ], 201);
    }

    /**
     * List past day closings.
     * GET /pos/day-closings?restaurant_id=&from=&to=
     */
    public function index(Request $request)
    {
        $this->checkPermission('view-reports');
        $validated = $request->validate([
            'restaurant_id' => 'nullable|exists:restaurant_masters,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $query = PosDayClosing::with('restaurant', 'closedByUser')
            ->orderByDesc('closed_date');

        if (! empty($validated['restaurant_id'])) {
            $query->where('restaurant_id', $validated['restaurant_id']);
        }
        if (! empty($validated['from'])) {
            $query->where('closed_date', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->where('closed_date', '<=', $validated['to']);
        }

        $closings = $query->paginate($request->get('per_page', 20));

        return response()->json($closings);
    }

    /**
     * Compute summary for a restaurant and date.
     * Uses orders where closed_at date = closed_date (paid or void).
     */
    private function computeSummary(int $restaurantId, string $closedDate): array
    {
        $paidOrders = PosOrder::where('restaurant_id', $restaurantId)
            ->whereIn('status', ['paid', 'refunded'])
            ->whereDate('closed_at', $closedDate);

        $voidOrders = PosOrder::where('restaurant_id', $restaurantId)
            ->where('status', 'void')
            ->whereDate('closed_at', $closedDate);

        $orderIds = (clone $paidOrders)->pluck('id');

        $totals = (clone $paidOrders)->selectRaw(
            'COALESCE(SUM(subtotal), 0) as total_sales,
             COALESCE(SUM(discount_amount), 0) as total_discount,
             COALESCE(SUM(tax_amount), 0) as total_tax,
             COALESCE(SUM(service_charge_amount), 0) as total_service_charge,
             COALESCE(SUM(tip_amount), 0) as total_tip,
             COALESCE(SUM(total_amount), 0) as total_paid'
        )->first();

        // Refunds are attributed to the date they were PERFORMED, not the order date.
        // This is critical for balancing the cash drawer today vs yesterday.
        $refundData = \App\Models\PosOrderRefund::whereHas('order', function ($q) use ($restaurantId) {
                $q->where('restaurant_id', $restaurantId);
            })
            ->whereDate('refunded_at', $closedDate);

        $totalRefunded = (clone $refundData)->sum('amount');

        $paymentsByMethod = PosPayment::whereIn('order_id', $orderIds)
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $refundsByMethod = (clone $refundData)
            ->selectRaw('method, COALESCE(SUM(amount), 0) as total')
            ->groupBy('method')
            ->pluck('total', 'method');

        $orderCount = (clone $paidOrders)->count();
        $voidCount = (clone $voidOrders)->count();

        $cashTotal = (float) (($paymentsByMethod->get('cash', 0) ?? 0) - ($refundsByMethod->get('cash', 0) ?? 0));
        $cardTotal = (float) (($paymentsByMethod->get('card', 0) ?? 0) - ($refundsByMethod->get('card', 0) ?? 0));
        $upiTotal = (float) (($paymentsByMethod->get('upi', 0) ?? 0) - ($refundsByMethod->get('upi', 0) ?? 0));
        $roomChargeTotal = (float) (($paymentsByMethod->get('room_charge', 0) ?? 0) - ($refundsByMethod->get('room_charge', 0) ?? 0));

        return [
            'total_sales' => (float) ($totals->total_sales ?? 0),
            'total_discount' => (float) ($totals->total_discount ?? 0),
            'total_tax' => (float) ($totals->total_tax ?? 0),
            'total_service_charge' => (float) ($totals->total_service_charge ?? 0),
            'total_tip' => (float) ($totals->total_tip ?? 0),
            'total_paid' => (float) (($totals->total_paid ?? 0) - $totalRefunded),
            'cash_total' => max(0, $cashTotal),
            'card_total' => max(0, $cardTotal),
            'upi_total' => max(0, $upiTotal),
            'room_charge_total' => max(0, $roomChargeTotal),
            'order_count' => $orderCount,
            'void_count' => $voidCount,
        ];
    }
}
