<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;

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
            } elseif ($request->type === 'manual') {
                $query->whereNull('reference_type');
            }
        }

        return response()->json($query->latest()->get());
    }
}
