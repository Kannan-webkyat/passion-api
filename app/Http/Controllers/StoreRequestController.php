<?php

namespace App\Http\Controllers;

use App\Models\StoreRequest;
use App\Models\StoreRequestItem;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreRequestController extends Controller
{
    public function index()
    {
        return response()->json(
            StoreRequest::with(['department', 'fromLocation.department', 'toLocation', 'requester', 'items.item'])
                        ->latest()
                        ->get()
        );
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_location_id' => 'required|exists:inventory_locations,id',
            'to_location_id'   => 'required|exists:inventory_locations,id',
            'required_date'    => 'nullable|date',
            'notes'            => 'nullable|string',
            'items'            => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity'          => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();
        try {
            $location = InventoryLocation::findOrFail($validated['from_location_id']);

            // Security check: non-admin can only request for their own department
            if (!auth()->user()->hasRole('Admin')) {
                $userDeptIds = auth()->user()->departments()->pluck('departments.id')->toArray();
                
                if (!$location->department_id || !in_array($location->department_id, $userDeptIds)) {
                    return response()->json(['message' => 'Unauthorized: You can only request for your assigned department.'], 403);
                }
            }

            $storeRequest = StoreRequest::create([
                'request_number'   => 'REQ-' . date('Ymd') . '-' . strtoupper(uniqid()),
                'from_location_id' => $validated['from_location_id'],
                'to_location_id'   => $validated['to_location_id'],
                'department_id'     => $location->department_id,
                'required_date'    => $validated['required_date'],
                'requested_by'     => auth()->id(),
                'status'           => 'pending',
                'notes'            => $validated['notes'],
                'requested_at'     => now(),
            ]);

            foreach ($validated['items'] as $item) {
                StoreRequestItem::create([
                    'store_request_id'   => $storeRequest->id,
                    'inventory_item_id'  => $item['inventory_item_id'],
                    'quantity_requested' => $item['quantity'],
                    'quantity_issued'    => 0,
                ]);
            }

            DB::commit();
            return response()->json($storeRequest->load('items.item'), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function approve(StoreRequest $storeRequest)
    {
        if ($storeRequest->status !== 'pending') {
            return response()->json(['message' => 'Request already processsed'], 422);
        }

        $storeRequest->update([
            'status'      => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json($storeRequest);
    }

    public function reject(StoreRequest $storeRequest)
    {
        if ($storeRequest->status !== 'pending') {
            return response()->json(['message' => 'Request already processed'], 422);
        }

        $storeRequest->update([
            'status' => 'rejected',
            'notes' => $storeRequest->notes . ' (Rejected by ' . auth()->user()->name . ')',
        ]);

        return response()->json($storeRequest);
    }

    /**
     * Cancel a requisition (requesting department only).
     * Only pending requests can be cancelled; only by requester or same department.
     */
    public function cancel(StoreRequest $storeRequest)
    {
        if ($storeRequest->status !== 'pending') {
            return response()->json(['message' => 'Only pending requests can be cancelled'], 422);
        }

        $user = auth()->user();
        $isRequester = $storeRequest->requested_by === $user->id;
        $userDeptIds = $user->departments()->pluck('departments.id')->toArray();
        $isSameDept = $storeRequest->department_id && in_array($storeRequest->department_id, $userDeptIds);

        if (!$isRequester && !$isSameDept && !$user->hasRole('Admin')) {
            return response()->json(['message' => 'Only the requesting department can cancel this requisition'], 403);
        }

        $storeRequest->update([
            'status' => 'cancelled',
            'notes' => ($storeRequest->notes ? $storeRequest->notes . ' ' : '') . '(Cancelled by ' . $user->name . ')',
        ]);

        return response()->json($storeRequest);
    }

    public function issue(Request $request, StoreRequest $storeRequest)
    {
        if (!in_array($storeRequest->status, ['approved', 'partially_issued'])) {
            return response()->json(['message' => 'Request must be approved before issuance'], 422);
        }

        $storeRequest->load(['department', 'fromLocation', 'toLocation']);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:store_request_items,id',
            'items.*.quantity_issued' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $issueData) {
                $requestItem = StoreRequestItem::findOrFail($issueData['id']);
                $qtyToIssue = $issueData['quantity_issued'];

                if ($qtyToIssue <= 0) continue;

                // 1. Deduct from Source Location (e.g., Main Store)
                $sourceStock = DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $requestItem->inventory_item_id)
                    ->where('inventory_location_id', $storeRequest->to_location_id)
                    ->first();

                if (!$sourceStock || $sourceStock->quantity < $qtyToIssue) {
                    throw new \Exception("Insufficient stock in source location for item " . $requestItem->item->name);
                }

                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $requestItem->inventory_item_id)
                    ->where('inventory_location_id', $storeRequest->to_location_id)
                    ->decrement('quantity', $qtyToIssue);

                // 2. Add to Target Location (e.g., Bar Store)
                DB::table('inventory_item_locations')->updateOrInsert(
                    [
                        'inventory_item_id' => $requestItem->inventory_item_id,
                        'inventory_location_id' => $storeRequest->from_location_id
                    ],
                    [
                        'quantity' => DB::raw('quantity + ' . $qtyToIssue),
                        'updated_at' => now(),
                        'created_at' => now()
                    ]
                );

                // 3. Update Request Item
                $requestItem->increment('quantity_issued', $qtyToIssue);

                // 4. Log Transactions (Double Entry) — share a reference_id to link them
                $refId = (string) Str::uuid();

                InventoryTransaction::create([
                    'inventory_item_id' => $requestItem->inventory_item_id,
                    'inventory_location_id' => $storeRequest->to_location_id,
                    'department_id' => $storeRequest->department_id,
                    'type' => 'out',
                    'quantity' => $qtyToIssue,
                    'reason' => 'Store Issue',
                    'notes' => 'Issued to ' . $storeRequest->fromLocation->name . ' (Req: ' . $storeRequest->request_number . ')',
                    'user_id' => auth()->id(),
                    'department' => $storeRequest->department?->name,
                    'reference_id' => $refId,
                    'reference_type' => 'requisition',
                ]);

                InventoryTransaction::create([
                    'inventory_item_id' => $requestItem->inventory_item_id,
                    'inventory_location_id' => $storeRequest->from_location_id,
                    'department_id' => $storeRequest->department_id,
                    'type' => 'in',
                    'quantity' => $qtyToIssue,
                    'reason' => 'Store Receipt',
                    'notes' => 'Received from ' . $storeRequest->toLocation->name . ' (Req: ' . $storeRequest->request_number . ')',
                    'user_id' => auth()->id(),
                    'department' => $storeRequest->department?->name,
                    'reference_id' => $refId,
                    'reference_type' => 'requisition',
                ]);
            }

            // Update Request Status
            $allIssued = true;
            $anyIssued = false;
            foreach ($storeRequest->items as $item) {
                if ($item->quantity_issued < $item->quantity_requested) {
                    $allIssued = false;
                }
                if ($item->quantity_issued > 0) {
                    $anyIssued = true;
                }
            }

            $storeRequest->status = $allIssued ? 'issued' : ($anyIssued ? 'partially_issued' : 'approved');
            if ($allIssued) {
                $storeRequest->issued_at = now();
            }
            $storeRequest->save();

            DB::commit();
            return response()->json($storeRequest->load('items.item'));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
