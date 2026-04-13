<?php

namespace App\Http\Controllers;

use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\StoreRequest;
use App\Models\StoreRequestItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreRequestController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if (! $user) {
            abort(401, 'Unauthenticated.');
        }
        if (! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function reconcileRequestStatus(StoreRequest $storeRequest): void
    {
        $storeRequest->load('items');
        $hasPending = $storeRequest->items->contains(fn ($i) => (float) $i->quantity_pending_acceptance > 0);
        if ($hasPending) {
            $storeRequest->status = 'awaiting_acceptance';
            $storeRequest->save();

            return;
        }

        $allComplete = $storeRequest->items->every(
            fn ($i) => (float) $i->quantity_issued >= (float) $i->quantity_requested
        );
        if ($allComplete) {
            $storeRequest->status = 'issued';
            if (! $storeRequest->issued_at) {
                $storeRequest->issued_at = now();
            }
            $storeRequest->save();

            return;
        }

        $anyReceived = $storeRequest->items->contains(fn ($i) => (float) $i->quantity_issued > 0);
        $storeRequest->status = $anyReceived ? 'partially_issued' : 'approved';
        $storeRequest->save();
    }

    private function clearPendingIssuance(StoreRequest $storeRequest): void
    {
        StoreRequestItem::where('store_request_id', $storeRequest->id)
            ->where('quantity_pending_acceptance', '>', 0)
            ->update(['quantity_pending_acceptance' => 0]);

        $sr = StoreRequest::with('items')->findOrFail($storeRequest->id);
        $this->reconcileRequestStatus($sr);
    }

    private function userCanAcceptReceive(StoreRequest $storeRequest): bool
    {
        $user = auth()->user();
        if ($user->hasRole('Admin')) {
            return true;
        }
        if ($storeRequest->requested_by === $user->id) {
            return true;
        }
        $userDeptIds = $user->departments()->pluck('departments.id')->toArray();

        return $storeRequest->department_id && in_array($storeRequest->department_id, $userDeptIds);
    }

    public function index()
    {
        $this->checkPermission('create-requisition');
        $user = auth()->user();
        $query = StoreRequest::with(['department', 'fromLocation.department', 'toLocation', 'requester', 'items.item'])
            ->latest();

        // ── Departmental Fence ────────────────────────────────────────────────
        // Admins and Store Managers see everything.
        // Others only see requests for their assigned departments.
        if (! $user->hasRole('Admin') && ! $user->hasRole('Store Manager')) {
            $userDeptIds = $user->departments()->pluck('departments.id')->toArray();
            $query->whereIn('department_id', $userDeptIds);
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $this->checkPermission('create-requisition');
        $validated = $request->validate([
            'from_location_id' => 'required|exists:inventory_locations,id',
            'to_location_id' => 'required|exists:inventory_locations,id|different:from_location_id',
            'required_date' => 'nullable|date',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();
        try {
            $location = InventoryLocation::findOrFail($validated['from_location_id']);

            // Security check: non-admin can only request for their own department
            if (! auth()->user()->hasRole('Admin')) {
                $userDeptIds = auth()->user()->departments()->pluck('departments.id')->toArray();

                if (! $location->department_id || ! in_array($location->department_id, $userDeptIds)) {
                    return response()->json(['message' => 'Unauthorized: You can only request for your assigned department.'], 403);
                }
            }

            $storeRequest = StoreRequest::create([
                'request_number' => 'REQ-'.date('Ymd').'-'.strtoupper(uniqid()),
                'from_location_id' => $validated['from_location_id'],
                'to_location_id' => $validated['to_location_id'],
                'department_id' => $location->department_id,
                'required_date' => $validated['required_date'],
                'requested_by' => auth()->id(),
                'status' => 'pending',
                'notes' => $validated['notes'],
                'requested_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                StoreRequestItem::create([
                    'store_request_id' => $storeRequest->id,
                    'inventory_item_id' => $item['inventory_item_id'],
                    'quantity_requested' => $item['quantity'],
                    'quantity_issued' => 0,
                    'quantity_pending_acceptance' => 0,
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
        $this->checkPermission('manage-inventory');
        if ($storeRequest->status !== 'pending') {
            return response()->json(['message' => 'Request already processed'], 422);
        }

        $storeRequest->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return response()->json($storeRequest);
    }

    public function reject(Request $request, StoreRequest $storeRequest)
    {
        $this->checkPermission('manage-inventory');
        if ($storeRequest->status === 'awaiting_acceptance') {
            return response()->json([
                'message' => 'Recall the pending issuance before rejecting this requisition.',
            ], 422);
        }

        if (! in_array($storeRequest->status, ['pending', 'approved'])) {
            return response()->json(['message' => 'Cannot reject a fulfilled request'], 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:2000',
        ]);

        $rejector = auth()->user()->name;
        $reasonLine = trim($validated['reason']);
        $notesSuffix = ' (Rejected by '.$rejector.'. Reason: '.$reasonLine.')';

        $storeRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $reasonLine,
            'notes' => ($storeRequest->notes ? $storeRequest->notes.' ' : '').$notesSuffix,
        ]);

        return response()->json($storeRequest->load(['department', 'fromLocation', 'toLocation', 'requester', 'items.item']));
    }

    /**
     * Store commits quantities from the main store; stock does not move until the requesting party accepts.
     */
    public function issue(Request $request, StoreRequest $storeRequest)
    {
        $this->checkPermission('manage-inventory');
        if (! in_array($storeRequest->status, ['approved', 'partially_issued'])) {
            return response()->json(['message' => 'Request must be approved before issuance'], 422);
        }

        $hasOpenPending = $storeRequest->items()->where('quantity_pending_acceptance', '>', 0)->exists();
        if ($hasOpenPending) {
            return response()->json([
                'message' => 'A batch is already awaiting acceptance. Recall it or wait for the requesting party to accept.',
            ], 422);
        }

        $storeRequest->load(['department', 'fromLocation', 'toLocation', 'items.item']);

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|exists:store_request_items,id',
            'items.*.quantity_issued' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            foreach ($validated['items'] as $issueData) {
                $requestItem = StoreRequestItem::where('store_request_id', $storeRequest->id)
                    ->where('id', $issueData['id'])
                    ->firstOrFail();

                $qtyToCommit = (float) $issueData['quantity_issued'];

                if ($qtyToCommit <= 0) {
                    continue;
                }

                $remaining = (float) $requestItem->quantity_requested - (float) $requestItem->quantity_issued;
                if ($qtyToCommit > $remaining + 0.00001) {
                    throw new \Exception('Cannot commit more than remaining requested quantity for '.$requestItem->item->name);
                }

                /** @var object|null $sourceStock */
                $sourceStock = DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $requestItem->inventory_item_id)
                    ->where('inventory_location_id', $storeRequest->to_location_id)
                    ->lockForUpdate()
                    ->first();

                if (! $sourceStock || (float) $sourceStock->quantity < $qtyToCommit) {
                    throw new \Exception('Insufficient stock in source location for item '.$requestItem->item->name);
                }

                $requestItem->increment('quantity_pending_acceptance', $qtyToCommit);
            }

            $storeRequest->refresh()->load('items');
            $this->reconcileRequestStatus($storeRequest);

            DB::commit();

            return response()->json($storeRequest->load('items.item'));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Requesting party confirms receipt — stock moves from store to department.
     */
    public function accept(StoreRequest $storeRequest)
    {
        $this->checkPermission('create-requisition');
        if ($storeRequest->status !== 'awaiting_acceptance') {
            return response()->json(['message' => 'Nothing pending acceptance for this request'], 422);
        }

        if (! $this->userCanAcceptReceive($storeRequest)) {
            return response()->json(['message' => 'Only the requesting department can accept this delivery'], 403);
        }

        $storeRequest->load(['department', 'fromLocation', 'toLocation', 'items.item']);

        $hasPending = $storeRequest->items->contains(fn ($i) => (float) $i->quantity_pending_acceptance > 0);
        if (! $hasPending) {
            return response()->json(['message' => 'Nothing pending acceptance'], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($storeRequest->items as $requestItem) {
                $qtyToMove = (float) $requestItem->quantity_pending_acceptance;
                if ($qtyToMove <= 0) {
                    continue;
                }

                /** @var object|null $sourceStock */
                $sourceStock = DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $requestItem->inventory_item_id)
                    ->where('inventory_location_id', $storeRequest->to_location_id)
                    ->lockForUpdate()
                    ->first();

                if (! $sourceStock || (float) $sourceStock->quantity < $qtyToMove) {
                    throw new \Exception('Insufficient stock in source location for item '.$requestItem->item->name);
                }

                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $requestItem->inventory_item_id)
                    ->where('inventory_location_id', $storeRequest->to_location_id)
                    ->decrement('quantity', $qtyToMove);

                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $requestItem->inventory_item_id, 'inventory_location_id' => $storeRequest->from_location_id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', $requestItem->inventory_item_id)
                    ->where('inventory_location_id', $storeRequest->from_location_id)
                    ->increment('quantity', $qtyToMove);

                $requestItem->increment('quantity_issued', $qtyToMove);
                $requestItem->update(['quantity_pending_acceptance' => 0]);

                $refId = (string) Str::uuid();

                // Resolve unit cost for transaction audit trail (WAC per issue unit)
                $invItem = InventoryItem::find($requestItem->inventory_item_id);
                $unitCost = floatval($invItem?->cost_price ?? 0) / floatval($invItem?->conversion_factor ?? 1);

                InventoryTransaction::create([
                    'inventory_item_id' => $requestItem->inventory_item_id,
                    'inventory_location_id' => $storeRequest->to_location_id,
                    'department_id' => $storeRequest->department_id,
                    'type' => 'out',
                    'quantity' => $qtyToMove,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($qtyToMove * $unitCost, 2),
                    'reason' => 'Store Issue',
                    'notes' => 'Issued to '.$storeRequest->fromLocation->name.' (Req: '.$storeRequest->request_number.')',
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
                    'quantity' => $qtyToMove,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($qtyToMove * $unitCost, 2),
                    'reason' => 'Store Receipt',
                    'notes' => 'Received from '.$storeRequest->toLocation->name.' (Req: '.$storeRequest->request_number.')',
                    'user_id' => auth()->id(),
                    'department' => $storeRequest->department?->name,
                    'reference_id' => $refId,
                    'reference_type' => 'requisition',
                ]);

                // Sync cached current_stock column for the transferred item
                InventoryItem::syncStoredCurrentStockFromLocations($requestItem->inventory_item_id);
            }

            $storeRequest->refresh()->load('items');
            $this->reconcileRequestStatus($storeRequest);

            DB::commit();

            return response()->json($storeRequest->load('items.item'));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Store manager withdraws a committed batch before it is accepted (no stock was moved yet).
     */
    public function recallIssue(StoreRequest $storeRequest)
    {
        $this->checkPermission('manage-inventory');
        if ($storeRequest->status !== 'awaiting_acceptance') {
            return response()->json(['message' => 'No issuance is pending acceptance'], 422);
        }

        $user = auth()->user();
        $storeRequest->update([
            'notes' => ($storeRequest->notes ? $storeRequest->notes.' ' : '').'(Issuance recalled by '.$user->name.')',
        ]);
        $storeRequest->load('items');
        $this->clearPendingIssuance($storeRequest);

        return response()->json($storeRequest->fresh()->load(['department', 'fromLocation', 'toLocation', 'requester', 'items.item']));
    }

    /**
     * Cancel a requisition (requesting department only).
     * Pending / approved / awaiting acceptance (clears committed batch only).
     */
    public function cancel(StoreRequest $storeRequest)
    {
        $this->checkPermission('create-requisition');
        if (! in_array($storeRequest->status, ['pending', 'approved', 'awaiting_acceptance'])) {
            return response()->json(['message' => 'Cannot cancel a fulfilled request'], 422);
        }

        $user = auth()->user();
        $isRequester = $storeRequest->requested_by === $user->id;
        $userDeptIds = $user->departments()->pluck('departments.id')->toArray();
        $isSameDept = $storeRequest->department_id && in_array($storeRequest->department_id, $userDeptIds);

        if (! $isRequester && ! $isSameDept && ! $user->hasRole('Admin')) {
            return response()->json(['message' => 'Only the requesting department can cancel this requisition'], 403);
        }

        if ($storeRequest->status === 'awaiting_acceptance') {
            $storeRequest->update([
                'notes' => ($storeRequest->notes ? $storeRequest->notes.' ' : '').'(Pending issuance withdrawn by '.$user->name.')',
            ]);
            $storeRequest->load('items');
            $this->clearPendingIssuance($storeRequest);

            return response()->json($storeRequest->fresh()->load(['department', 'fromLocation', 'toLocation', 'requester', 'items.item']));
        }

        $storeRequest->update([
            'status' => 'cancelled',
            'notes' => ($storeRequest->notes ? $storeRequest->notes.' ' : '').'(Cancelled by '.$user->name.')',
        ]);

        return response()->json($storeRequest);
    }
}
