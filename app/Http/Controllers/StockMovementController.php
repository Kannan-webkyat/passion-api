<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;
use App\Models\PosOrder;
use App\Models\PosOrderItem;

class StockMovementController extends Controller
{
    public function index(\Illuminate\Http\Request $request)
    {
        $query = InventoryTransaction::with(['item.issueUom', 'location', 'department']);

        // Smart Search (Item name, SKU, Reference, or Notes)
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $searchTerm = '%' . $request->search . '%';
                $q->whereHas('item', function ($iq) use ($searchTerm) {
                    $iq->where('name', 'like', $searchTerm)
                      ->orWhere('sku', 'like', $searchTerm);
                })
                ->orWhere('reference_type', 'like', $searchTerm)
                ->orWhere('notes', 'like', $searchTerm)
                ->orWhere('reason', 'like', $searchTerm);
            });
        }

        // Location 
        if ($request->filled('location_id') && $request->location_id !== 'all') {
            $query->where('inventory_location_id', '=', $request->location_id);
        }

        // Date
        if ($request->filled('date')) {
            $query->whereDate('created_at', '=', $request->date);
        }

        // Type / Source Filter
        if ($request->filled('type') && $request->type !== 'all') {
            if ($request->type === 'transfer') {
                $query->whereNotNull('reference_id');
            } elseif ($request->type === 'in') {
                $query->where('type', '=', 'in');
            } elseif ($request->type === 'out') {
                $query->where('type', '=', 'out');
            } elseif ($request->type === 'sales') {
                $query->whereIn('reference_type', ['pos_order', 'pos_order_batch']);
            } elseif ($request->type === 'production') {
                $query->where('reference_type', '=', 'production');
            } elseif ($request->type === 'requisition') {
                $query->where('reference_type', '=', 'requisition');
            } elseif ($request->type === 'purchase') {
                $query->where('reference_type', '=', 'purchase_order');
            } elseif ($request->type === 'manual') {
                $query->whereNull('reference_type');
            }
        }

        $rows = $query->latest()->get();
        $rows->each(function (InventoryTransaction $tx) {
            $tx->setAttribute(
                'outlet_name',
                $this->resolvePosOutletName($tx->reference_type, $tx->reference_id)
            );
        });

        return response()->json($rows);
    }

    /**
     * POS movements store reference_type + reference_id; resolve consuming outlet (restaurant) name.
     */
    private function resolvePosOutletName(?string $referenceType, $referenceId): ?string
    {
        if ($referenceType === null || $referenceId === null || $referenceId === '') {
            return null;
        }

        $refId = (string) $referenceId;

        if (in_array($referenceType, ['pos_order', 'pos_order_sync_cancel', 'pos_order_sync_reduce', 'pos_order_sync_partial', 'pos_order_void', 'pos_order_item_void'], true)) {
            $orderId = (int) $refId;

            return $orderId > 0 ? $this->outletNameForOrderId($orderId) : null;
        }

        if ($referenceType === 'pos_order_batch') {
            $orderId = (int) explode('-', $refId, 2)[0];

            return $orderId > 0 ? $this->outletNameForOrderId($orderId) : null;
        }

        if ($referenceType === 'pos_order_line_ready') {
            $item = PosOrderItem::with('order.restaurant')->find((int) $refId);

            return $item?->order?->restaurant?->name;
        }

        return null;
    }

    private function outletNameForOrderId(int $orderId): ?string
    {
        $order = PosOrder::with('restaurant')->find($orderId);

        return $order?->restaurant?->name;
    }
}
