<?php

namespace App\Http\Controllers;

use App\Models\InventoryTransaction;

class StockMovementController extends Controller
{
    public function index()
    {
        return response()->json(
            InventoryTransaction::with(['item.issueUom', 'location', 'department'])
                ->latest()
                ->get()
        );
    }
}
