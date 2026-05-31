<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesHousekeepingPermissions;
use App\Http\Controllers\Concerns\AuthorizesSpatiePermissions;
use App\Events\BookingChargesUpdated;
use App\Events\DailyRoomCleaningDeskNotify;
use App\Events\HousekeepingStateUpdated;
use App\Models\Booking;
use App\Models\BookingExtraCharge;
use App\Models\BookingSegment;
use App\Models\DailyRoomCleaning;
use App\Models\DailyRoomCleaningConsumption;
use App\Models\HousekeepingJob;
use App\Models\HousekeepingJobLine;
use App\Models\InventoryItem;
use App\Models\InventoryLocation;
use App\Models\InventoryTransaction;
use App\Models\LaundryRequest;
use App\Models\MenuItem;
use App\Models\PosOrder;
use App\Models\PosOrderItem;
use App\Models\PosPayment;
use App\Models\RestaurantMaster;
use App\Models\Room;
use App\Models\RoomParTemplate;
use App\Models\RoomStatusBlock;
use App\Models\Setting;
use App\Models\User;
use App\Support\CheckoutInspectionInspector;
use App\Support\CheckoutInspectionPenaltyAmount;
use App\Support\RoomParInventoryContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HousekeepingController extends Controller
{
    use AuthorizesHousekeepingPermissions;
    use AuthorizesSpatiePermissions;

    /**
     * Shared filters for housekeeping index lists (floor, room type, optional calendar overlap).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\RoomStatusBlock>  $query
     */
    private function applyHousekeepingListFilters($query, array $validated, string $d, string $dNext, bool $overlapOnly): void
    {
        if ($overlapOnly) {
            $query->where('start_date', '<', $dNext)
                ->where('end_date', '>', $d);
        }

        $query->whereHas('room', function ($q) use ($validated) {
            if (! empty($validated['floor'])) {
                $q->where('floor', '=', $validated['floor'], 'and');
            }
            if (! empty($validated['room_type_id'])) {
                $q->where('room_type_id', '=', $validated['room_type_id'], 'and');
            }
        });
    }

    /**
     * Adds inspector_name from snapshot.inspector_user_id for checkout inspection history/detail lists.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\RoomStatusBlock>  $blocks
     * @return \Illuminate\Support\Collection<int, \App\Models\RoomStatusBlock>
     */
    private function withCheckoutInspectionInspectorNames($blocks)
    {
        $ids = [];
        foreach ($blocks as $b) {
            $snap = $b->inspection_snapshot;
            if (is_array($snap) && ! empty($snap['inspector_user_id'])) {
                $ids[] = (int) $snap['inspector_user_id'];
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return $blocks;
        }
        return $blocks->map(function (RoomStatusBlock $b) {
            $snap = $b->inspection_snapshot;
            $uid = is_array($snap) ? (int) ($snap['inspector_user_id'] ?? 0) : 0;
            $stored = is_array($snap) && isset($snap['inspector_name']) ? (string) $snap['inspector_name'] : null;
            $b->setAttribute(
                'inspector_name',
                CheckoutInspectionInspector::displayNameForUserId($uid > 0 ? $uid : null, $stored),
            );

            return $b;
        });
    }

    /**
     * List active housekeeping status blocks (dirty / in cleaning).
     * By default returns all active blocks so rooms stay visible even when the dirty window is tied to
     * a future checkout day on the chart. Pass overlap_only=1 to restrict to blocks overlapping `date`.
     *
     * Checkout inspection board: pass checkout_scope=pending|inspected|history (pending = default main list).
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
            'floor' => 'nullable|string|max:50',
            'room_type_id' => 'nullable|exists:room_types,id',
            'hk_status' => 'nullable|in:dirty,cleaning,inspected,pending_inspection,all',
            'checkout_scope' => 'nullable|in:pending,inspected,history',
        ]);

        $d = isset($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : Carbon::today()->toDateString();
        $dNext = Carbon::parse($d)->addDay()->toDateString();
        $overlapOnly = $request->boolean('overlap_only');

        if (! empty($validated['checkout_scope'])) {
            $this->allowHousekeepingViewSection([self::HK_CHECKOUT]);
            $scope = $validated['checkout_scope'];
            $query = RoomStatusBlock::query()->with(['room.roomType']);

            if ($scope === 'pending') {
                $query->where('is_active', '=', true, 'and')
                    ->where('status', '=', 'pending_inspection');
            } elseif ($scope === 'inspected') {
                $query->where('is_active', '=', true, 'and')
                    ->where('status', '=', 'inspected')
                    ->whereNotNull('inspection_snapshot');
            } else {
                // history: completed checkout inspections (closed blocks with saved snapshot)
                $query->where('is_active', '=', false, 'and')
                    ->where('status', '=', 'inspected')
                    ->whereNotNull('inspection_snapshot');
            }

            $this->applyHousekeepingListFilters($query, $validated, $d, $dNext, $overlapOnly);

            $blocks = $scope === 'history'
                ? $query->orderByDesc('id')->limit(200)->get()
                : $query->orderBy('room_id')->orderBy('id')->get();

            $blocks = $this->withCheckoutInspectionInspectorNames($blocks);

            return response()->json([
                'date' => $d,
                'blocks' => $blocks,
            ]);
        }

        $hkStatus = $validated['hk_status'] ?? 'all';

        $statusPermission = [
            'dirty' => [self::HK_DIRTY],
            'cleaning' => [self::HK_CLEANING],
            'inspected' => [self::HK_CLEAN],
            'pending_inspection' => [self::HK_CHECKOUT],
        ];
        if ($hkStatus === 'all') {
            $this->allowHousekeepingNav();
        } else {
            $this->allowHousekeepingViewSection($statusPermission[$hkStatus] ?? [self::HK_DIRTY]);
        }

        $statuses = $hkStatus === 'all' ? ['dirty', 'cleaning', 'inspected', 'pending_inspection'] : [$hkStatus];

        // List all active HK blocks — checkout may be scheduled on a future calendar day while the room
        // is already dirty today; strict date overlap would hide those from housekeeping.
        $query = RoomStatusBlock::query()
            ->with(['room.roomType'])
            ->where('is_active', '=', true, 'and')
            ->whereIn('status', $statuses);

        $this->applyHousekeepingListFilters($query, $validated, $d, $dNext, $overlapOnly);

        $blocks = $query
            ->orderBy('room_id')
            ->orderBy('id')
            ->get();

        return response()->json([
            'date' => $d,
            'blocks' => $blocks,
        ]);
    }

    /**
     * Compact counts for housekeeping sub-navigation tabs (matches default workboard filters).
     */
    public function navCounts()
    {
        $this->allowHousekeepingNav();

        $dirty = (int) RoomStatusBlock::query()
            ->where('is_active', '=', true, 'and')
            ->where('status', '=', 'dirty')
            ->count();

        $cleaning = (int) RoomStatusBlock::query()
            ->where('is_active', '=', true, 'and')
            ->where('status', '=', 'cleaning')
            ->count();

        $inspected = (int) RoomStatusBlock::query()
            ->where('is_active', '=', true, 'and')
            ->where('status', '=', 'inspected')
            ->count();

        $roomStockShortfall = 0;
        $roomRows = Room::query()
            ->where('is_active', '=', true, 'and')
            ->whereNotNull('par_template_id')
            ->get(['id']);
        foreach ($roomRows as $room) {
            $ctx = RoomParInventoryContext::forRoomId((int) $room->id);
            if (! $ctx) {
                continue;
            }
            if ((bool) ($ctx['template_assigned'] ?? false) && (float) ($ctx['to_stock_total'] ?? 0) > 0.00001) {
                $roomStockShortfall++;
            }
        }

        // Checkout Inspection tab: count rooms awaiting inspection only (main list default).
        $checkoutInspection = (int) RoomStatusBlock::query()
            ->where('is_active', '=', true, 'and')
            ->where('status', '=', 'pending_inspection')
            ->count();

        // Daily room cleaning nav badge: pending service only (matches daily board default filter).
        $dailyCleaning = $this->dailyCleaningPendingCountForNav(Carbon::today());

        $laundryPendingPickup = (int) LaundryRequest::query()
            ->where('status', '=', LaundryRequest::STATUS_PENDING_PICKUP)
            ->count();

        $laundryReady = (int) LaundryRequest::query()
            ->where('status', '=', LaundryRequest::STATUS_READY)
            ->count();

        $laundryAwaitingPost = (int) LaundryRequest::query()
            ->where('status', '=', LaundryRequest::STATUS_DELIVERED)
            ->whereNull('posted_at')
            ->count();

        $laundryActionable = $laundryPendingPickup + $laundryReady + $laundryAwaitingPost;

        return response()->json([
            'dirty' => $dirty,
            'checkout_inspection' => $checkoutInspection,
            'cleaning' => $cleaning,
            'daily_cleaning' => $dailyCleaning,
            'inspected' => $inspected,
            'room_stock_shortfall' => $roomStockShortfall,
            'laundry' => $laundryActionable,
            'laundry_pending_pickup' => $laundryPendingPickup,
            'laundry_ready' => $laundryReady,
            'laundry_awaiting_post' => $laundryAwaitingPost,
        ]);
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    private function housekeepingChecklistTemplate(): array
    {
        return [
            ['key' => 'change_sheets', 'label' => 'Change sheets'],
            ['key' => 'clean_bathroom', 'label' => 'Clean bathroom'],
            ['key' => 'vacuum_floor', 'label' => 'Vacuum / mop floor'],
            ['key' => 'dust_surfaces', 'label' => 'Dust surfaces'],
            ['key' => 'trash_removed', 'label' => 'Remove trash'],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function allowedHousekeepingChecklistKeys(): array
    {
        return array_column($this->housekeepingChecklistTemplate(), 'key');
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    private function sanitizeHousekeepingChecklistDone(?array $raw): array
    {
        if ($raw === null) {
            return [];
        }
        $allowed = array_flip($this->allowedHousekeepingChecklistKeys());
        $out = [];
        foreach ($raw as $k => $v) {
            if (! is_string($k) || ! isset($allowed[$k])) {
                continue;
            }
            $out[$k] = (bool) $v;
        }

        return $out;
    }

    /**
     * Housekeeping catalog for the sidebar:
     * - amenities: Guest Amenities categories (unless for_daily_cleaning=1: PAR kind=amenity + room qty &gt; 0)
     * - minibar: direct-sale inventory items that have a linked menu_item_id (so we can room-charge via POS)
     * - checklist/assets templates: static for now
     */
    public function catalog()
    {
        $this->allowHousekeepingNav();

        $validated = request()->validate([
            'room_id' => 'nullable|integer|exists:rooms,id',
            'for_checkout_inspection' => 'nullable|boolean',
            'for_daily_cleaning' => 'nullable|boolean',
        ]);

        $roomIdEarly = isset($validated['room_id']) ? (int) $validated['room_id'] : null;
        $roomContextEarly = $roomIdEarly ? $this->roomContextPayload($roomIdEarly) : null;

        $amenityCats = \App\Models\InventoryCategory::query()
            ->where('name', '=', 'Guest Amenities (Consumables)', 'and')
            ->orWhere('parent_id', function ($q) {
                $q->select('id')
                    ->from('inventory_categories')
                    ->where('name', '=', 'Guest Amenities (Consumables)', 'and')
                    ->limit(1);
            })
            ->pluck('id')
            ->toArray();

        // Include roots named like "Guest Amenities*" and their direct children (covers "(Consumables)" vs plain names).
        $guestRootIds = \App\Models\InventoryCategory::query()
            ->where('name', 'like', 'Guest Amenities%', 'and')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();
        $guestChildIds = $guestRootIds === [] ? [] : \App\Models\InventoryCategory::query()
            ->whereIn('parent_id', $guestRootIds, 'and', false)
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        $amenityCatIds = collect($amenityCats)
            ->merge($guestRootIds)
            ->merge($guestChildIds)
            ->map(fn($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $amenities = $amenityCatIds === []
            ? collect()
            : InventoryItem::query()
            ->whereIn('category_id', $amenityCatIds, 'and', false)
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category_id']);

        // Occupied-room daily cleaning: only consumable amenities (PAR kind=amenity) with qty > 0 in room.
        if (request()->boolean('for_daily_cleaning') && $roomContextEarly) {
            $onHandMap = $roomContextEarly['on_hand_by_item_id'] ?? [];
            $amenityParIds = [];
            foreach ($roomContextEarly['par_lines'] ?? [] as $ln) {
                if (($ln['kind'] ?? '') === 'amenity') {
                    $amenityParIds[(int) ($ln['inventory_item_id'] ?? 0)] = true;
                }
            }
            $positiveIds = [];
            foreach ($onHandMap as $itemId => $qty) {
                $id = (int) $itemId;
                if ($id <= 0) {
                    continue;
                }
                if ((float) $qty <= 0.0001) {
                    continue;
                }
                if (! isset($amenityParIds[$id])) {
                    continue;
                }
                $positiveIds[$id] = true;
            }
            $amenities = $positiveIds === []
                ? collect()
                : InventoryItem::query()
                ->whereIn('id', array_keys($positiveIds), 'and', false)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'category_id']);
        }

        // Minibar items: direct-sale inventory items with a linked menu item for POS posting
        $minibar = InventoryItem::query()
            ->where('is_direct_sale', '=', true, 'and')
            ->with(['category:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'category_id']);

        $menuCols = ['id', 'inventory_item_id', 'name', 'price'];
        if (Schema::hasColumn('menu_items', 'tax_rate')) {
            $menuCols[] = 'tax_rate';
        }
        $menuByInventory = MenuItem::query()
            ->whereNotNull('inventory_item_id', 'and')
            ->get($menuCols);

        $menuMap = $menuByInventory->keyBy(fn($m) => (int) $m->inventory_item_id);

        $minibarPayload = $minibar->map(function ($i) use ($menuMap) {
            $m = $menuMap[(int) $i->id] ?? null;

            return [
                'inventory_item_id' => (int) $i->id,
                'sku' => (string) $i->sku,
                'name' => (string) $i->name,
                'category' => $i->category?->name,
                'menu_item_id' => $m ? (int) $m->id : null,
                'menu_price' => $m ? (float) $m->price : null,
                'menu_tax_rate' => $m && Schema::hasColumn('menu_items', 'tax_rate') ? (float) ($m->tax_rate ?? 0) : null,
            ];
        })->values();

        $assets = [
            ['key' => 'coffee_maker', 'label' => 'Coffee maker'],
            ['key' => 'electric_kettle', 'label' => 'Electric kettle'],
            ['key' => 'hair_dryer', 'label' => 'Hair dryer'],
            ['key' => 'television', 'label' => 'Television'],
            ['key' => 'mini_fridge', 'label' => 'Mini-fridge'],
            ['key' => 'safe_deposit_box', 'label' => 'Safe-deposit box'],
            ['key' => 'iron', 'label' => 'Iron'],
            ['key' => 'ironing_board', 'label' => 'Ironing board'],
        ];

        $checklist = $this->housekeepingChecklistTemplate();

        $roomId = $roomIdEarly;
        $roomContext = $roomContextEarly ?? $this->roomContextPayload($roomId);
        $checkoutInspectionAssets = $this->checkoutInspectionAssetsFromContext($roomContext);

        $forCheckout = ! empty($validated['for_checkout_inspection']);
        $minibarOut = $minibarPayload;
        if ($forCheckout && $roomContext) {
            $onHand = $roomContext['on_hand_by_item_id'] ?? [];
            $minibarParIds = [];
            foreach ($roomContext['par_lines'] ?? [] as $ln) {
                if (($ln['kind'] ?? '') === 'minibar') {
                    $minibarParIds[(int) ($ln['inventory_item_id'] ?? 0)] = true;
                }
            }
            $minibarOut = $minibarPayload->filter(function ($row) use ($onHand, $minibarParIds, $roomContext) {
                $id = (int) ($row['inventory_item_id'] ?? 0);
                $qty = (float) ($onHand[$id] ?? 0);
                if ($qty <= 0.0001) {
                    return false;
                }
                if (isset($minibarParIds[$id])) {
                    return true;
                }
                foreach ($roomContext['on_hand_items'] ?? [] as $oh) {
                    if ((int) ($oh['inventory_item_id'] ?? 0) === $id && ! empty($oh['is_direct_sale'])) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return response()->json([
            'amenities' => $amenities,
            'minibar' => $minibarOut,
            'assets' => $assets,
            'checkout_inspection_assets' => $checkoutInspectionAssets,
            'checklist' => $checklist,
            'room_context' => $roomContext,
        ]);
    }

    /**
     * Movable assets for checkout inspection (inv_{id} keys).
     * Only items physically on hand in the room location are included.
     */
    private function checkoutInspectionAssetsFromContext(?array $roomContext): array
    {
        if (! $roomContext || empty($roomContext['par_lines'])) {
            return [];
        }
        $onHand = $roomContext['on_hand_by_item_id'] ?? [];
        $itemIds = [];
        foreach ($roomContext['par_lines'] as $ln) {
            if (($ln['kind'] ?? '') !== 'asset') {
                continue;
            }
            $iid = (int) ($ln['inventory_item_id'] ?? 0);
            if ($iid > 0 && (float) ($onHand[$iid] ?? 0) > 0.0001) {
                $itemIds[$iid] = true;
            }
        }
        $itemsById = $itemIds === []
            ? collect()
            : InventoryItem::query()
            ->whereIn('id', array_keys($itemIds), 'and', false)
            ->get(['id', 'name', 'sku', 'cost_price', 'conversion_factor', 'inspection_penalty_charge'])
            ->keyBy('id');

        $out = [];
        foreach ($roomContext['par_lines'] as $ln) {
            if (($ln['kind'] ?? '') !== 'asset') {
                continue;
            }
            $iid = (int) ($ln['inventory_item_id'] ?? 0);
            if ($iid <= 0) {
                continue;
            }
            $onHandQty = (float) ($onHand[$iid] ?? 0);
            if ($onHandQty <= 0.0001) {
                continue;
            }
            /** @var InventoryItem|null $item */
            $item = $itemsById->get($iid);
            $itemCost = $item
                ? CheckoutInspectionPenaltyAmount::issueUnitCost(
                    (float) ($item->cost_price ?? 0),
                    (float) ($item->conversion_factor ?? 1),
                )
                : 0.0;
            $additional = $item
                ? round(max(0.0, (float) ($item->inspection_penalty_charge ?? 0)), 2)
                : 0.0;
            $out[] = [
                'key' => 'inv_' . $iid,
                'inventory_item_id' => $iid,
                'label' => (string) ($ln['item_name'] ?? ($item->name ?? 'Asset')),
                'sku' => (string) ($ln['sku'] ?? ($item->sku ?? '')),
                'par_qty' => (float) ($ln['par_qty'] ?? 0),
                'on_hand' => $onHandQty,
                'item_cost' => $itemCost,
                'inspection_penalty_charge' => $additional,
                'unit_damage_charge' => round($itemCost + $additional, 2),
            ];
        }

        return $out;
    }

    private function roomHasParAssetLines(?array $roomContext): bool
    {
        if (! $roomContext) {
            return false;
        }
        foreach ($roomContext['par_lines'] ?? [] as $ln) {
            if (($ln['kind'] ?? '') === 'asset') {
                return true;
            }
        }

        return false;
    }

    /** Legacy static asset keys when room PAR has no asset lines. */
    private function defaultInspectionAssetKeys(): array
    {
        return [
            'coffee_maker',
            'electric_kettle',
            'hair_dryer',
            'television',
            'mini_fridge',
            'safe_deposit_box',
            'iron',
            'ironing_board',
        ];
    }

    /** Allowed `assets[].key` values for checkout inspection apply (PAR inv_* + legacy slugs). */
    private function allowedInspectionAssetKeysForRoom(int $roomId): array
    {
        $ctx = $this->roomContextPayload($roomId);
        $fromPar = [];
        foreach ($this->checkoutInspectionAssetsFromContext($ctx) as $row) {
            $fromPar[] = (string) ($row['key'] ?? '');
        }
        if ($this->roomHasParAssetLines($ctx)) {
            return $fromPar;
        }

        if ($fromPar !== []) {
            return $fromPar;
        }

        return $this->defaultInspectionAssetKeys();
    }

    /**
     * Compute minibar (inventory cost) + asset penalty totals for inspection preview (no DB writes).
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, float>  $onHandByItem
     * @param  array<string, mixed>  $penalties
     * @return array<string, mixed>
     */
    private function buildCheckoutInspectionChargePreview(array $validated, array $onHandByItem, array $penalties): array
    {
        $minibarLines = [];
        $minibarTotal = 0.0;
        foreach (($validated['minibar'] ?? []) as $ln) {
            $itemId = (int) ($ln['inventory_item_id'] ?? 0);
            $qty = (float) ($ln['qty'] ?? 0);
            if ($qty <= 0 || $itemId <= 0) {
                continue;
            }
            /** @var InventoryItem|null $item */
            $item = InventoryItem::query()->find($itemId, ['id', 'name', 'sku', 'cost_price', 'conversion_factor']);
            if (! $item) {
                continue;
            }
            $conv = max(1.0, (float) ($item->conversion_factor ?: 1));
            $unitCost = (float) ($item->cost_price ?? 0) / $conv;
            $lineTotal = round($unitCost * $qty, 2);
            $minibarLines[] = [
                'inventory_item_id' => $itemId,
                'name' => (string) ($item->name ?? ''),
                'sku' => (string) ($item->sku ?? ''),
                'qty' => $qty,
                'unit_amount' => round($unitCost, 2),
                'line_total' => $lineTotal,
            ];
            $minibarTotal += $lineTotal;
        }

        $assetLines = [];
        $assetTotal = 0.0;
        foreach (($validated['assets'] ?? []) as $a) {
            $key = (string) ($a['key'] ?? '');
            $label = (string) ($a['label'] ?? '');
            $penKey = isset($a['penalty_key']) ? trim((string) $a['penalty_key']) : '';
            $lineQty = isset($a['qty']) ? max(1, (int) $a['qty']) : 1;
            $invItemId = isset($a['inventory_item_id']) ? (int) $a['inventory_item_id'] : null;
            if (str_starts_with($key, 'inv_')) {
                $fromKey = (int) substr($key, 4);
                if ($fromKey > 0) {
                    $invItemId = $invItemId ?: $fromKey;
                }
            }

            [$amount, $mapLabel, $itemCost, $additionalPenalty] = CheckoutInspectionPenaltyAmount::resolveForAsset(
                $invItemId,
                $penKey,
                $penalties,
            );
            if ($mapLabel !== null && $mapLabel !== '') {
                $label = $mapLabel;
            }

            $amount = round(max(0.0, $amount), 2);
            $lineTotal = round($amount * $lineQty, 2);
            $assetLines[] = [
                'key' => $key,
                'label' => $label,
                'qty' => $lineQty,
                'inventory_item_id' => $invItemId ?: null,
                'penalty_key' => $penKey !== '' ? $penKey : null,
                'item_cost' => $itemCost,
                'inspection_penalty_charge' => $additionalPenalty,
                'unit_amount' => $amount,
                'line_total' => $lineTotal,
            ];
            if ($lineTotal > 0.0001) {
                $assetTotal += $lineTotal;
            }
        }

        return [
            'minibar_lines' => $minibarLines,
            'minibar_total' => round($minibarTotal, 2),
            'asset_lines' => $assetLines,
            'asset_total' => round($assetTotal, 2),
            'grand_total' => round($minibarTotal + $assetTotal, 2),
        ];
    }

    /**
     * Guest stay + inspector + penalty map for checkout inspection UI.
     */
    public function checkoutInspectionContext(Room $room)
    {
        $this->allowHousekeepingNav();

        $booking = $this->activeBookingForRoom((int) $room->id);
        $penaltiesRaw = (string) Setting::get('checkout_inspection_penalties', '{}');
        $penaltiesJson = json_decode($penaltiesRaw, true);
        $penalties = is_array($penaltiesJson) ? $penaltiesJson : [];

        $user = Auth::user();

        return response()->json([
            'room_id' => (int) $room->id,
            'room_number' => (string) $room->room_number,
            'booking' => $booking ? [
                'id' => (int) $booking->id,
                'guest_name' => (string) ($booking->guest_name ?? trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''))),
                'check_in' => $booking->check_in,
                'check_out' => $booking->check_out,
                'check_in_at' => $booking->check_in_at,
                'check_out_at' => $booking->check_out_at,
            ] : null,
            'inspector' => [
                'user_id' => $user ? (int) $user->id : null,
                'name' => $user ? (string) $user->name : null,
            ],
            'penalties' => $penalties,
        ]);
    }

    private function roomContextPayload(?int $roomId): ?array
    {
        return RoomParInventoryContext::forRoomId($roomId);
    }

    /**
     * Transition dirty → cleaning (room chart shows Cleaning).
     */
    public function startCleaning(RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_DIRTY, self::HK_CLEANING]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if ($roomStatusBlock->status !== 'dirty') {
            return response()->json([
                'message' => 'Only rooms marked dirty can start cleaning.',
            ], 422);
        }

        $roomStatusBlock->update(['status' => 'cleaning']);
        Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'cleaning']);

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomStatusBlock->room_id], 'start_cleaning');

        return response()->json($roomStatusBlock->load('room.roomType'));
    }

    /**
     * Draft/update housekeeping job lines while cleaning is in progress.
     */
    public function upsertJob(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_DIRTY, self::HK_CLEANING]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if (! in_array($roomStatusBlock->status, ['cleaning', 'dirty'], true)) {
            return response()->json(['message' => 'This room is not in a housekeeping workflow state.'], 422);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
            'checklist' => 'nullable|array',
            'checklist.*.key' => 'required_with:checklist|string|max:100',
            'checklist.*.label' => 'required_with:checklist|string|max:255',
            'checklist.*.done' => 'required_with:checklist|boolean',
            'amenities' => 'nullable|array',
            'amenities.*.inventory_item_id' => 'required_with:amenities|exists:inventory_items,id',
            'amenities.*.qty' => 'required_with:amenities|numeric|min:0',
            'amenities.*.found_qty' => 'nullable|numeric|min:0',
            'minibar' => 'nullable|array',
            'minibar.*.inventory_item_id' => 'required_with:minibar|exists:inventory_items,id',
            'minibar.*.menu_item_id' => 'nullable|exists:menu_items,id',
            'minibar.*.qty' => 'required_with:minibar|numeric|min:0',
            'minibar.*.found_qty' => 'nullable|numeric|min:0',
            'assets' => 'nullable|array',
            'assets.*.key' => 'required_with:assets|string|max:100',
            'assets.*.label' => 'required_with:assets|string|max:255',
            'assets.*.status' => 'required_with:assets|string|in:ok,needs_repair,missing',
            'assets.*.note' => 'nullable|string|max:500',
        ]);

        $userId = Auth::id();

        DB::beginTransaction();
        try {
            /** @var HousekeepingJob $job */
            $job = HousekeepingJob::firstOrCreate(
                ['room_status_block_id' => $roomStatusBlock->id],
                [
                    'room_id' => $roomStatusBlock->room_id,
                    'status' => 'in_progress',
                    'started_by' => $userId,
                ]
            );

            $job->update([
                'remarks' => $validated['remarks'] ?? null,
            ]);

            // Replace draft lines (simple and predictable)
            $job->lines()->delete();

            foreach (($validated['checklist'] ?? []) as $it) {
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'checklist',
                    'qty' => 0,
                    'meta' => [
                        'key' => $it['key'],
                        'label' => $it['label'],
                        'done' => (bool) $it['done'],
                    ],
                ]);
            }

            foreach (($validated['amenities'] ?? []) as $it) {
                $qty = (float) ($it['qty'] ?? 0);
                $hasFound = array_key_exists('found_qty', $it);
                if ($qty <= 0.0001 && ! $hasFound) {
                    continue;
                }
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'amenity',
                    'inventory_item_id' => (int) $it['inventory_item_id'],
                    'qty' => $qty,
                    'meta' => $hasFound ? ['found_qty' => (float) $it['found_qty']] : null,
                ]);
            }

            foreach (($validated['minibar'] ?? []) as $it) {
                $qty = (float) ($it['qty'] ?? 0);
                $hasFound = array_key_exists('found_qty', $it);
                if ($qty <= 0.0001 && ! $hasFound) {
                    continue;
                }
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'minibar',
                    'inventory_item_id' => (int) $it['inventory_item_id'],
                    'menu_item_id' => $it['menu_item_id'] ? (int) $it['menu_item_id'] : null,
                    'qty' => $qty,
                    'meta' => $hasFound ? ['found_qty' => (float) $it['found_qty']] : null,
                ]);
            }

            foreach (($validated['assets'] ?? []) as $it) {
                HousekeepingJobLine::create([
                    'housekeeping_job_id' => $job->id,
                    'kind' => 'asset',
                    'qty' => 0,
                    'meta' => [
                        'key' => $it['key'],
                        'label' => $it['label'],
                        'status' => $it['status'],
                        'note' => $it['note'] ?? null,
                    ],
                ]);
            }

            DB::commit();

            return response()->json($job->fresh()->load('lines'));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Finish cleaning: deduct stock, post minibar to folio (POS room_charge), and move to inspected.
     */
    public function finish(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_CLEANING]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if ($roomStatusBlock->status !== 'cleaning') {
            return response()->json([
                'message' => 'Start cleaning before finishing.',
            ], 422);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
        ]);

        $userId = Auth::id();

        DB::beginTransaction();
        try {
            /** @var HousekeepingJob $job */
            $job = HousekeepingJob::firstOrCreate(
                ['room_status_block_id' => $roomStatusBlock->id],
                [
                    'room_id' => $roomStatusBlock->room_id,
                    'status' => 'in_progress',
                    'started_by' => $userId,
                ]
            );
            if (array_key_exists('remarks', $validated)) {
                $job->remarks = $validated['remarks'];
            }
            $job->finished_by = $userId;

            $job->load('lines');

            $hkStore = InventoryLocation::where('name', '=', 'Housekeeping Store', 'and')->first()
                ?: InventoryLocation::where('type', '=', 'main_store', 'and')->first();

            if (! $hkStore) {
                return response()->json(['message' => 'No inventory location available for housekeeping.'], 422);
            }

            $assetProblem = false;
            $assetNotes = [];

            foreach ($job->lines as $ln) {
                if ($ln->kind === 'asset') {
                    $st = (string) (($ln->meta['status'] ?? '') ?: '');
                    if (in_array($st, ['needs_repair', 'missing'], true)) {
                        $assetProblem = true;
                        $assetNotes[] = ($ln->meta['label'] ?? $ln->meta['key'] ?? 'Asset') . ': ' . $st;
                    }
                }
            }

            // Room-location logic:
            // - staff enters found_qty (what is still in-room)
            // - system computes consumed = max(0, par - found)
            // - for amenities/minibar, consumption is deducted from Room location (if it exists)
            // - replenishment is an explicit transfer from HK Store -> Room location using ln.qty

            $roomLoc = InventoryLocation::where('room_id', '=', $roomStatusBlock->room_id, 'and')->first();
            $roomTypeId = (int) (Room::where('id', '=', $roomStatusBlock->room_id, 'and')->value('room_type_id') ?? 0);
            $template = RoomParTemplate::where('room_type_id', '=', $roomTypeId, 'and')
                ->orderBy('name', 'asc')
                ->with('lines')
                ->first();
            $parMap = [];
            if ($template) {
                foreach ($template->lines as $pl) {
                    $parMap[(int) $pl->inventory_item_id] = (float) ($pl->par_qty ?? 0);
                }
            }

            $lines = $job->lines->whereIn('kind', ['amenity', 'minibar'])->values();
            foreach ($lines as $ln) {
                if (! $ln->inventory_item_id) {
                    continue;
                }
                $itemId = (int) $ln->inventory_item_id;

                $par = (float) ($parMap[$itemId] ?? 0);
                $found = isset($ln->meta['found_qty']) ? (float) $ln->meta['found_qty'] : null;
                $consumed = ($found === null) ? null : max(0.0, $par - $found);

                // 1) Replenishment transfer (HK Store -> Room location)
                $replenishQty = (float) ($ln->qty ?? 0);
                if ($replenishQty > 0) {
                    // Deduct from HK store
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $itemId, 'inventory_location_id' => $hkStore->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', '=', $itemId, 'and')
                        ->where('inventory_location_id', '=', $hkStore->id, 'and')
                        ->decrement('quantity', $replenishQty);

                    // Add to room location if available
                    if ($roomLoc) {
                        DB::table('inventory_item_locations')->updateOrInsert(
                            ['inventory_item_id' => $itemId, 'inventory_location_id' => $roomLoc->id],
                            ['updated_at' => now(), 'created_at' => now()]
                        );
                        DB::table('inventory_item_locations')
                            ->where('inventory_item_id', '=', $itemId, 'and')
                            ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                            ->increment('quantity', $replenishQty);
                    }

                    $item = InventoryItem::lockForUpdate()->find($itemId);
                    $unitCost = floatval($item?->cost_price ?? 0) / floatval($item?->conversion_factor ?: 1);
                    $refId = (string) Str::uuid();

                    InventoryTransaction::create([
                        'inventory_item_id' => $itemId,
                        'inventory_location_id' => $hkStore->id,
                        'type' => 'out',
                        'quantity' => $replenishQty,
                        'unit_cost' => round($unitCost, 4),
                        'total_cost' => round($replenishQty * $unitCost, 2),
                        'reason' => 'HK replenish to room',
                        'notes' => 'Housekeeping replenish',
                        'user_id' => $userId,
                        'reference_id' => $refId,
                        'reference_type' => 'housekeeping',
                    ]);
                    if ($roomLoc) {
                        InventoryTransaction::create([
                            'inventory_item_id' => $itemId,
                            'inventory_location_id' => $roomLoc->id,
                            'type' => 'in',
                            'quantity' => $replenishQty,
                            'unit_cost' => round($unitCost, 4),
                            'total_cost' => round($replenishQty * $unitCost, 2),
                            'reason' => 'HK replenish to room',
                            'notes' => 'Housekeeping replenish',
                            'user_id' => $userId,
                            'reference_id' => $refId,
                            'reference_type' => 'housekeeping',
                        ]);
                    }

                    InventoryItem::syncStoredCurrentStockFromLocations($itemId);
                }

                // 2) Consumption deduction from room location (baseline - found)
                if ($roomLoc && $consumed !== null && $consumed > 0) {
                    DB::table('inventory_item_locations')->updateOrInsert(
                        ['inventory_item_id' => $itemId, 'inventory_location_id' => $roomLoc->id],
                        ['updated_at' => now(), 'created_at' => now()]
                    );
                    DB::table('inventory_item_locations')
                        ->where('inventory_item_id', '=', $itemId, 'and')
                        ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                        ->decrement('quantity', $consumed);

                    $item = InventoryItem::lockForUpdate()->find($itemId);
                    $unitCost = floatval($item?->cost_price ?? 0) / floatval($item?->conversion_factor ?: 1);
                    InventoryTransaction::create([
                        'inventory_item_id' => $itemId,
                        'inventory_location_id' => $roomLoc->id,
                        'type' => 'out',
                        'quantity' => $consumed,
                        'unit_cost' => round($unitCost, 4),
                        'total_cost' => round($consumed * $unitCost, 2),
                        'reason' => 'Room consumption',
                        'notes' => 'Consumed (par vs found) during housekeeping',
                        'user_id' => $userId,
                        'reference_id' => (string) Str::uuid(),
                        'reference_type' => 'housekeeping',
                    ]);
                    InventoryItem::syncStoredCurrentStockFromLocations($itemId);
                }
            }

            // Minibar billing via POS room_charge
            $minibarLines = $job->lines->where('kind', 'minibar')->values();
            if ($minibarLines->isNotEmpty()) {
                $booking = $this->activeBookingForRoom($roomStatusBlock->room_id);
                if ($booking) {
                    $this->postMinibarRoomCharge($booking, $minibarLines, $userId);
                }
            }

            // If any asset is missing/broken, put room on maintenance until cleared.
            if ($assetProblem) {
                $note = 'HK asset issue: ' . implode('; ', array_slice($assetNotes, 0, 3));
                RoomStatusBlock::create([
                    'room_id' => $roomStatusBlock->room_id,
                    'status' => 'maintenance',
                    'start_date' => Carbon::today()->toDateString(),
                    'end_date' => Carbon::today()->addYears(5)->toDateString(),
                    'note' => substr($note, 0, 255),
                    'is_active' => true,
                    'created_by' => $userId,
                ]);
                Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'maintenance']);
                $job->issues_summary = substr($note, 0, 500);
            } else {
                // Close HK workflow and release room — no separate "mark available" step from housekeeping.
                $roomStatusBlock->update([
                    'status' => 'inspected',
                    'is_active' => false,
                ]);
                Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'available']);
                $job->status = 'completed';
            }

            $job->save();

            DB::commit();

            HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomStatusBlock->room_id], 'finish_cleaning');

            return response()->json([
                'message' => $assetProblem ? 'Cleaning finished.' : 'Cleaning complete. Room is available.',
                'block' => $roomStatusBlock->fresh()->load('room.roomType'),
                'job' => $job->fresh()->load('lines'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Supervisor step: inspected → available.
     */
    public function markInspected(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_CLEAN]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }
        if ($roomStatusBlock->status !== 'inspected') {
            return response()->json(['message' => 'Room is not awaiting inspection.'], 422);
        }

        $roomStatusBlock->update(['is_active' => false]);
        Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'available']);

        $job = HousekeepingJob::where('room_status_block_id', $roomStatusBlock->id)->first();
        if ($job) {
            $job->status = 'completed';
            $job->save();
        }

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomStatusBlock->room_id], 'mark_inspected');

        return response()->json([
            'message' => 'Room inspected and available.',
            'block' => $roomStatusBlock->fresh()->load('room.roomType'),
        ]);
    }

    private function activeBookingForRoom(int $roomId): ?Booking
    {
        $now = now();

        // Prefer segments (split-stays)
        $seg = BookingSegment::query()
            ->where('room_id', '=', $roomId, 'and')
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<=', $now)
            ->where('check_out_at', '>=', $now)
            ->with('booking')
            ->orderByDesc('id')
            ->first();

        $b = $seg?->booking;
        if ($b && $b->status === 'checked_in') {
            return $b;
        }

        // Fallback: booking.room_id for legacy
        return Booking::query()
            ->where('room_id', '=', $roomId, 'and')
            ->where('status', '=', 'checked_in', 'and')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Booking tied to checkout inspection on this room (departed guest or vacated leg), not the next arrival.
     */
    private function bookingForCheckoutInspectionRoom(int $roomId, string $startDate, string $endDate): ?Booking
    {
        // Pre-checkout inspection (pending_inspection): guest is usually still checked in.
        $active = $this->activeBookingForRoom($roomId);
        if ($active instanceof Booking) {
            return $active;
        }

        $departed = BookingSegment::query()
            ->where('room_id', '=', $roomId, 'and')
            ->where(function ($q) {
                $q->where('status', '=', 'checked_out', 'or')
                    ->whereHas('booking', fn($b) => $b->where('status', '=', 'checked_out'));
            })
            ->where('check_in_at', '<', $endDate)
            ->where('check_out_at', '>', $startDate)
            ->with('booking')
            ->orderByDesc('check_out_at')
            ->orderByDesc('id')
            ->first();

        if ($departed?->booking instanceof Booking) {
            return $departed->booking;
        }

        $stay = BookingSegment::query()
            ->where('room_id', '=', $roomId, 'and')
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->where('check_in_at', '<', $endDate)
            ->where('check_out_at', '>', $startDate)
            ->with('booking')
            ->orderByDesc('id')
            ->first();

        return $stay?->booking instanceof Booking ? $stay->booking : null;
    }

    private function postMinibarRoomCharge(Booking $booking, $minibarLines, ?int $userId): void
    {
        $restaurant = RestaurantMaster::where('name', '=', 'OTTAAL', 'and')->first()
            ?: RestaurantMaster::query()->orderBy('id', 'asc')->first();
        if (! $restaurant) {
            return;
        }

        $hasPosOrders = static function (string $col): bool {
            return Schema::hasColumn('pos_orders', $col);
        };
        $hasPosOrderItems = static function (string $col): bool {
            return Schema::hasColumn('pos_order_items', $col);
        };
        $hasPosPayments = static function (string $col): bool {
            return Schema::hasColumn('pos_payments', $col);
        };

        // Create POS order in paid state with room_charge payment.
        $orderData = [
            'restaurant_id' => $restaurant->id,
            'covers' => 1,
            'status' => 'paid',
            'opened_at' => now(),
            'closed_at' => now(),
            'notes' => 'Minibar posting (housekeeping)',
        ];
        if ($hasPosOrders('order_type')) {
            $orderData['order_type'] = 'room_service';
        }
        if ($hasPosOrders('table_id')) {
            $orderData['table_id'] = null;
        }
        if ($hasPosOrders('business_date')) {
            $orderData['business_date'] = Carbon::today()->toDateString();
        }
        if ($hasPosOrders('waiter_id')) {
            $orderData['waiter_id'] = null;
        }
        if ($hasPosOrders('opened_by')) {
            $orderData['opened_by'] = $userId;
        }
        if ($hasPosOrders('room_id')) {
            $orderData['room_id'] = $booking->room_id;
        }
        if ($hasPosOrders('booking_id')) {
            $orderData['booking_id'] = $booking->id;
        }
        if ($hasPosOrders('customer_name')) {
            $orderData['customer_name'] = $booking->guest_name ?? trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));
        }
        if ($hasPosOrders('customer_phone')) {
            $orderData['customer_phone'] = $booking->phone ?? null;
        }

        $order = PosOrder::create($orderData);

        $subtotal = 0.0;
        $taxAmount = 0.0;
        $total = 0.0;

        foreach ($minibarLines as $ln) {
            $qty = (float) ($ln->qty ?? 0);
            if ($qty <= 0) {
                continue;
            }

            $menuItemId = (int) ($ln->menu_item_id ?? 0);
            if ($menuItemId <= 0) {
                continue;
            }

            $menu = MenuItem::find($menuItemId, ['id', 'name', 'price', 'tax_rate']);
            if (! $menu) {
                continue;
            }

            $unit = (float) ($menu->price ?? 0);
            $rate = (float) ($menu->tax_rate ?? 0);
            $lineSubtotal = $unit * $qty;
            $lineTax = $rate > 0 ? ($lineSubtotal * $rate / 100) : 0;
            $lineTotal = $lineSubtotal + $lineTax;

            $oi = [
                'order_id' => $order->id,
                'menu_item_id' => $menu->id,
                'quantity' => (int) round($qty),
                'unit_price' => round($unit, 2),
                'tax_rate' => round($rate, 2),
                'line_total' => round($lineTotal, 2),
                'kot_sent' => false,
                'notes' => 'Minibar (HK)',
            ];
            if ($hasPosOrderItems('price_tax_inclusive')) {
                $oi['price_tax_inclusive'] = false;
            }
            if ($hasPosOrderItems('status')) {
                $oi['status'] = 'active';
            }
            if ($hasPosOrderItems('inventory_deducted')) {
                $oi['inventory_deducted'] = true;
            }

            PosOrderItem::create($oi);

            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
            $total += $lineTotal;
        }

        $order->update([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($total, 2),
        ]);

        $pay = [
            'order_id' => $order->id,
            'method' => 'room_charge',
            'amount' => round($total, 2),
            'paid_at' => now(),
            'received_by' => $userId,
        ];
        if ($hasPosPayments('business_date')) {
            $pay['business_date'] = Carbon::today();
        }
        PosPayment::create($pay);

        // Keep booking.extra_charges aligned (UI uses this as “posted to room” total).
        $booking->extra_charges = (float) ($booking->extra_charges ?? 0) + round($total, 2);
        $booking->save();
    }

    /**
     * Backward compatible endpoint: direct cleaning → available.
     * (Kept for any older UI that still calls mark-cleaned.)
     */
    public function markCleaned(RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_CLEANING]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }

        if ($roomStatusBlock->status !== 'cleaning') {
            return response()->json([
                'message' => 'Start cleaning before marking the room as cleaned.',
            ], 422);
        }

        $roomStatusBlock->update(['is_active' => false]);
        Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'available']);

        HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomStatusBlock->room_id], 'mark_cleaned');

        return response()->json([
            'message' => 'Room marked as cleaned.',
            'block' => $roomStatusBlock->fresh()->load('room.roomType'),
        ]);
    }

    /**
     * Checkout inspection: clear room with no extra charges (pending_inspection -> inspected snapshot).
     * Inspected block stays active on the room chart until checkout or supervisor release.
     */
    public function checkoutInspectionClear(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_CHECKOUT]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }
        if ($roomStatusBlock->status !== 'pending_inspection') {
            return response()->json(['message' => 'Room is not pending inspection.'], 422);
        }

        $userId = Auth::id();
        $inspectorName = CheckoutInspectionInspector::displayNameForUserId(
            $userId ? (int) $userId : null,
            Auth::user() ? (string) Auth::user()->name : null,
        );
        $startDate = (string) $roomStatusBlock->start_date;
        $endDate = (string) $roomStatusBlock->end_date;
        $roomId = (int) $roomStatusBlock->room_id;
        $booking = $this->bookingForCheckoutInspectionRoom($roomId, $startDate, $endDate);
        $bookingId = $booking ? (int) $booking->id : null;

        DB::beginTransaction();
        try {
            $roomStatusBlock->update([
                'is_active' => false,
                'note' => substr(trim((string) ($roomStatusBlock->note ?: '')) ?: 'Inspection cleared', 0, 255),
            ]);

            $snapshot = [
                'remarks' => null,
                'minibar' => [],
                'assets' => [],
                'cleared' => true,
                'submitted_at' => now()->toIso8601String(),
                'inspected_at' => now()->toIso8601String(),
                'inspector_user_id' => $userId,
                'inspector_name' => $inspectorName,
                'booking_id' => $bookingId,
                'room_id' => $roomId,
            ];

            $newBlock = RoomStatusBlock::create([
                'room_id' => $roomStatusBlock->room_id,
                'status' => 'inspected',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'note' => 'Checkout inspection cleared (no extra charges)',
                'inspection_snapshot' => $snapshot,
                'is_active' => true,
                'created_by' => $userId,
            ]);

            Room::where('id', '=', $roomStatusBlock->room_id, 'and')->update(['status' => 'inspected']);

            DB::commit();

            HousekeepingStateUpdated::dispatchIfEnabled([(int) $roomStatusBlock->room_id], 'checkout_inspection_clear');

            return response()->json([
                'message' => 'Inspection completed. Room marked inspected on the chart.',
                'block' => $newBlock->fresh()->load('room.roomType'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Validate-only checkout inspection payload and return server-side charge preview.
     */
    public function checkoutInspectionValidate(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_CHECKOUT]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }
        if ($roomStatusBlock->status !== 'pending_inspection') {
            return response()->json(['message' => 'Room is not pending inspection.'], 422);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
            'minibar' => 'nullable|array',
            'minibar.*.inventory_item_id' => 'required_with:minibar|exists:inventory_items,id',
            'minibar.*.qty' => 'required_with:minibar|numeric|min:0',
            'assets' => 'nullable|array',
            'assets.*.key' => 'required_with:assets|string|max:100',
            'assets.*.label' => 'required_with:assets|string|max:255',
            'assets.*.status' => 'required_with:assets|string|in:missing,damaged',
            'assets.*.penalty_key' => 'nullable|string|max:100',
            'assets.*.notes' => 'nullable|string|max:2000',
            'assets.*.qty' => 'nullable|integer|min:1|max:999',
            'assets.*.inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'room_condition' => 'nullable|array',
            'linen_check' => 'nullable|array',
            'maintenance_flags' => 'nullable|array',
            'photo_urls' => 'nullable|array|max:20',
            'photo_urls.*' => 'nullable|string|max:2048',
        ]);

        $roomId = (int) $roomStatusBlock->room_id;
        $booking = $this->activeBookingForRoom($roomId);
        if (! $booking) {
            return response()->json(['message' => 'No active booking found for this room.'], 422);
        }

        $penaltiesRaw = (string) Setting::get('checkout_inspection_penalties', '{}');
        $penaltiesJson = json_decode($penaltiesRaw, true);
        $penalties = is_array($penaltiesJson) ? $penaltiesJson : [];

        $roomLoc = InventoryLocation::where('room_id', '=', $roomId, 'and')->first();
        if (! $roomLoc) {
            return response()->json(['message' => 'No inventory location mapped to this room.'], 422);
        }

        $onHandByItem = [];
        $locRows = DB::table('inventory_item_locations')
            ->where('inventory_location_id', '=', $roomLoc->id, 'and')
            ->pluck('quantity', 'inventory_item_id');
        foreach ($locRows as $itemId => $qty) {
            $onHandByItem[(int) $itemId] = (float) $qty;
        }

        $allowedAssetKeys = $this->allowedInspectionAssetKeysForRoom($roomId);
        foreach (($validated['assets'] ?? []) as $a) {
            $k = (string) ($a['key'] ?? '');
            if ($k === '' || ! in_array($k, $allowedAssetKeys, true)) {
                return response()->json(['message' => 'Invalid or disallowed asset key: ' . $k], 422);
            }
        }

        foreach (($validated['minibar'] ?? []) as $ln) {
            $itemId = (int) ($ln['inventory_item_id'] ?? 0);
            $qty = (float) ($ln['qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            $onHand = (float) ($onHandByItem[$itemId] ?? 0);
            if ($qty > $onHand + 0.0001) {
                return response()->json([
                    'message' => 'Minibar quantity exceeds on-hand stock for item #' . $itemId . ' (max ' . round($onHand, 4) . ').',
                ], 422);
            }
        }

        $preview = $this->buildCheckoutInspectionChargePreview($validated, $onHandByItem, $penalties);

        return response()->json([
            'ok' => true,
            'booking_id' => (int) $booking->id,
            'preview' => $preview,
        ]);
    }

    /**
     * Checkout inspection: apply incidental charges and deduct minibar consumption.
     * - Deduct minibar qty from room inventory location
     * - Append booking_extra_charges lines
     * - Increment bookings.extra_charges so reception totals update
     * - Transition room to inspected (HK snapshot stored on the new block for reopening the sidebar)
     */
    public function checkoutInspectionApply(Request $request, RoomStatusBlock $roomStatusBlock)
    {
        $this->allowHousekeepingOperate([self::HK_CHECKOUT]);

        if (! $roomStatusBlock->is_active) {
            return response()->json(['message' => 'This status block is no longer active.'], 422);
        }
        if ($roomStatusBlock->status !== 'pending_inspection') {
            return response()->json(['message' => 'Room is not pending inspection.'], 422);
        }

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:5000',
            'minibar' => 'nullable|array',
            'minibar.*.inventory_item_id' => 'required_with:minibar|exists:inventory_items,id',
            'minibar.*.qty' => 'required_with:minibar|numeric|min:0',
            'assets' => 'nullable|array',
            'assets.*.key' => 'required_with:assets|string|max:100',
            'assets.*.label' => 'required_with:assets|string|max:255',
            'assets.*.status' => 'required_with:assets|string|in:missing,damaged',
            'assets.*.penalty_key' => 'nullable|string|max:100',
            'assets.*.notes' => 'nullable|string|max:2000',
            'assets.*.qty' => 'nullable|integer|min:1|max:999',
            'assets.*.inventory_item_id' => 'nullable|integer|exists:inventory_items,id',
            'room_condition' => 'nullable|array',
            'linen_check' => 'nullable|array',
            'maintenance_flags' => 'nullable|array',
            'photo_urls' => 'nullable|array|max:20',
            'photo_urls.*' => 'nullable|string|max:2048',
        ]);

        $userId = Auth::id();
        $inspectorName = CheckoutInspectionInspector::displayNameForUserId(
            $userId ? (int) $userId : null,
            Auth::user() ? (string) Auth::user()->name : null,
        );
        $startDate = (string) $roomStatusBlock->start_date;
        $endDate = (string) $roomStatusBlock->end_date;

        $roomId = (int) $roomStatusBlock->room_id;
        $booking = $this->bookingForCheckoutInspectionRoom($roomId, $startDate, $endDate);
        if (! $booking) {
            return response()->json(['message' => 'No booking found for this room inspection.'], 422);
        }

        $penaltiesRaw = (string) Setting::get('checkout_inspection_penalties', '{}');
        $penaltiesJson = json_decode($penaltiesRaw, true);
        $penalties = is_array($penaltiesJson) ? $penaltiesJson : [];

        $roomLoc = InventoryLocation::where('room_id', '=', $roomId, 'and')->first();
        if (! $roomLoc) {
            return response()->json(['message' => 'No inventory location mapped to this room.'], 422);
        }

        $onHandByItem = [];
        $locRows = DB::table('inventory_item_locations')
            ->where('inventory_location_id', '=', $roomLoc->id, 'and')
            ->pluck('quantity', 'inventory_item_id');
        foreach ($locRows as $itemId => $qty) {
            $onHandByItem[(int) $itemId] = (float) $qty;
        }

        $allowedAssetKeys = $this->allowedInspectionAssetKeysForRoom($roomId);
        foreach (($validated['assets'] ?? []) as $a) {
            $k = (string) ($a['key'] ?? '');
            if ($k === '' || ! in_array($k, $allowedAssetKeys, true)) {
                return response()->json(['message' => 'Invalid or disallowed asset key: ' . $k], 422);
            }
        }

        $bookingExtraChargesHasDescription = Schema::hasColumn('booking_extra_charges', 'description');

        DB::beginTransaction();
        try {
            $chargeTotal = 0.0;

            // Minibar: deduct from room location and charge at inventory unit cost (cost_price / conversion_factor).
            foreach (($validated['minibar'] ?? []) as $ln) {
                $itemId = (int) $ln['inventory_item_id'];
                $qty = (float) ($ln['qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }

                $onHand = (float) ($onHandByItem[$itemId] ?? 0);
                if ($qty > $onHand + 0.0001) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Minibar quantity exceeds on-hand stock for item #' . $itemId . ' (max ' . round($onHand, 4) . ').',
                    ], 422);
                }

                /** @var InventoryItem|null $item */
                $item = InventoryItem::lockForUpdate()->find($itemId);
                if (! $item) {
                    continue;
                }

                $conv = max(1.0, (float) ($item->conversion_factor ?: 1));
                $unitCost = (float) ($item->cost_price ?? 0) / $conv;
                $lineTotal = round($unitCost * $qty, 2);

                // Deduct stock from room location
                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $itemId, 'inventory_location_id' => $roomLoc->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', '=', $itemId, 'and')
                    ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                    ->decrement('quantity', $qty);

                InventoryTransaction::create([
                    'inventory_item_id' => $itemId,
                    'inventory_location_id' => $roomLoc->id,
                    'type' => 'out',
                    'quantity' => $qty,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => $lineTotal,
                    'reason' => 'Checkout inspection consumption',
                    'notes' => 'Minibar/snacks consumed during checkout inspection',
                    'user_id' => $userId,
                    'reference_id' => (string) Str::uuid(),
                    'reference_type' => 'checkout_inspection',
                ]);
                InventoryItem::syncStoredCurrentStockFromLocations($itemId);

                $minibarLabel = (string) ($item->name ?? 'Minibar item');
                $minibarRow = [
                    'booking_id' => $booking->id,
                    'source' => 'inspection',
                    'kind' => 'minibar',
                    'label' => $minibarLabel,
                    'qty' => $qty,
                    'unit_amount' => round($unitCost, 2),
                    'total_amount' => $lineTotal,
                    'meta' => [
                        'inventory_item_id' => $itemId,
                        'sku' => (string) ($item->sku ?? ''),
                    ],
                ];
                if ($bookingExtraChargesHasDescription) {
                    $minibarRow['description'] = Str::limit(
                        sprintf('Checkout inspection — minibar consumption: %s × %s', $minibarLabel, (string) $qty),
                        500,
                    );
                }
                BookingExtraCharge::create($minibarRow);

                $chargeTotal += $lineTotal;
            }

            // Assets: charge based on penalties mapping
            foreach (($validated['assets'] ?? []) as $a) {
                $key = (string) $a['key'];
                $label = (string) $a['label'];
                $status = (string) $a['status'];
                $penKey = isset($a['penalty_key']) ? trim((string) $a['penalty_key']) : '';
                $lineNotes = isset($a['notes']) ? trim((string) $a['notes']) : '';
                $lineQty = isset($a['qty']) ? max(1, (int) $a['qty']) : 1;
                $invItemId = isset($a['inventory_item_id']) ? (int) $a['inventory_item_id'] : null;
                if (str_starts_with($key, 'inv_')) {
                    $fromKey = (int) substr($key, 4);
                    if ($fromKey > 0) {
                        $invItemId = $invItemId ?: $fromKey;
                    }
                }

                [$amount, $mapLabel, $itemCost, $additionalPenalty] = CheckoutInspectionPenaltyAmount::resolveForAsset(
                    $invItemId,
                    $penKey,
                    $penalties,
                );
                if ($mapLabel !== null && $mapLabel !== '') {
                    $label = $mapLabel;
                }

                $amount = round(max(0.0, $amount), 2);
                $unitAmount = $amount;
                $lineTotal = round($unitAmount * $lineQty, 2);

                $assetDesc = sprintf(
                    'Checkout inspection — asset %s: %s',
                    $status === 'missing' || $status === 'damaged' ? $status : 'issue',
                    $label,
                );
                if ($itemCost > 0.0001 || $additionalPenalty > 0.0001) {
                    $assetDesc .= sprintf(
                        ' (item cost %s + penalty %s = %s per unit)',
                        number_format($itemCost, 2, '.', ''),
                        number_format($additionalPenalty, 2, '.', ''),
                        number_format($unitAmount, 2, '.', ''),
                    );
                } elseif ($penKey !== '') {
                    $assetDesc .= sprintf(' (legacy penalty key: %s)', $penKey);
                }
                if ($lineNotes !== '') {
                    $assetDesc .= ' — ' . Str::limit($lineNotes, 240);
                }
                $assetRow = [
                    'booking_id' => $booking->id,
                    'source' => 'inspection',
                    'kind' => 'asset_penalty',
                    'label' => $label,
                    'qty' => $lineQty,
                    'unit_amount' => $unitAmount,
                    'total_amount' => $lineTotal,
                    'meta' => [
                        'asset_key' => $key,
                        'asset_status' => $status,
                        'penalty_key' => $penKey !== '' ? $penKey : null,
                        'notes' => $lineNotes !== '' ? $lineNotes : null,
                        'inventory_item_id' => $invItemId ?: null,
                        'item_cost' => $itemCost,
                        'inspection_penalty_charge' => $additionalPenalty,
                    ],
                ];
                if ($bookingExtraChargesHasDescription) {
                    $assetRow['description'] = Str::limit($assetDesc, 500);
                }
                BookingExtraCharge::create($assetRow);

                if ($lineTotal > 0.0001) {
                    $chargeTotal += $lineTotal;
                }
            }

            // Update booking extra charges total (reception UI uses this).
            if ($chargeTotal > 0.0001) {
                $booking->extra_charges = (float) ($booking->extra_charges ?? 0) + round($chargeTotal, 2);
            }
            if (array_key_exists('remarks', $validated) && trim((string) $validated['remarks']) !== '') {
                $booking->notes = trim((string) ($booking->notes ?? '')) . "\n" .
                    '[Inspection: ' . trim((string) $validated['remarks']) . ' on ' . now()->format('Y-m-d H:i:s') . ']';
            }
            $booking->save();

            $snapshotAssets = [];
            foreach (($validated['assets'] ?? []) as $a) {
                if (! is_array($a)) {
                    continue;
                }
                $key = (string) ($a['key'] ?? '');
                $penKey = isset($a['penalty_key']) ? trim((string) $a['penalty_key']) : '';
                $invItemId = isset($a['inventory_item_id']) ? (int) $a['inventory_item_id'] : null;
                if (str_starts_with($key, 'inv_')) {
                    $fromKey = (int) substr($key, 4);
                    if ($fromKey > 0) {
                        $invItemId = $invItemId ?: $fromKey;
                    }
                }
                [$unit,, $itemCost, $additionalPenalty] = CheckoutInspectionPenaltyAmount::resolveForAsset(
                    $invItemId,
                    $penKey,
                    $penalties,
                );
                $snapshotAssets[] = array_merge($a, [
                    'item_cost' => $itemCost,
                    'inspection_penalty_charge' => $additionalPenalty,
                    'unit_damage_charge' => $unit,
                ]);
            }

            $snapshot = [
                'remarks' => array_key_exists('remarks', $validated) ? trim((string) $validated['remarks']) : null,
                'minibar' => [],
                'assets' => $snapshotAssets,
                'cleared' => false,
                'submitted_at' => now()->toIso8601String(),
                'booking_id' => (int) $booking->id,
                'room_id' => $roomId,
                'inspector_user_id' => $userId,
                'inspector_name' => $inspectorName,
                'inspected_at' => now()->toIso8601String(),
                'room_condition' => $validated['room_condition'] ?? null,
                'linen_check' => $validated['linen_check'] ?? null,
                'maintenance_flags' => $validated['maintenance_flags'] ?? null,
                'photo_urls' => $validated['photo_urls'] ?? null,
            ];
            foreach (($validated['minibar'] ?? []) as $ln) {
                $itemId = (int) ($ln['inventory_item_id'] ?? 0);
                $qty = (float) ($ln['qty'] ?? 0);
                if ($qty <= 0 || $itemId <= 0) {
                    continue;
                }
                $item = InventoryItem::find($itemId, ['id', 'name', 'sku']);
                $snapshot['minibar'][] = [
                    'inventory_item_id' => $itemId,
                    'qty' => $qty,
                    'name' => $item ? (string) $item->name : '',
                    'sku' => $item ? (string) ($item->sku ?? '') : '',
                ];
            }

            // Hand off to inspected (guest may still be in-house; room chart keeps segment via cellInfo).
            $roomStatusBlock->update(['is_active' => false]);
            $newBlock = RoomStatusBlock::create([
                'room_id' => $roomId,
                'status' => 'inspected',
                'start_date' => $startDate,
                'end_date' => $endDate,
                'note' => 'Checkout inspection complete (charges applied)',
                'inspection_snapshot' => $snapshot,
                'is_active' => true,
                'created_by' => $userId,
            ]);
            Room::where('id', '=', $roomId, 'and')->update(['status' => 'inspected']);

            DB::commit();

            if (config('broadcasting.default') !== 'null') {
                $bookingIdBc = (int) $booking->id;
                $extraBc = (float) ($booking->extra_charges ?? 0);
                $addedBc = round($chargeTotal, 2);
                App::terminating(function () use ($bookingIdBc, $extraBc, $addedBc) {
                    try {
                        event(new BookingChargesUpdated(
                            $bookingIdBc,
                            $extraBc,
                            $addedBc,
                            'Inspection Complete - Additional Charges Applied'
                        ));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
            }

            HousekeepingStateUpdated::dispatchIfEnabled([$roomId], 'checkout_inspection_apply');

            return response()->json([
                'message' => 'Inspection completed. Charges applied; room marked inspected on the chart.',
                'booking_id' => (int) $booking->id,
                'extra_charges' => (float) ($booking->extra_charges ?? 0),
                'added_amount' => round($chargeTotal, 2),
                'block' => $newBlock->fresh()->load('room.roomType'),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Occupied rooms (checked-in) overlapping a calendar service date — one row per room.
     *
     * @return \Illuminate\Support\Collection<int, BookingSegment>
     */
    private function dailyCleaningOccupiedSegments(Carbon $serviceDay): \Illuminate\Support\Collection
    {
        $dayStart = $serviceDay->copy()->startOfDay();
        $dayEnd = $serviceDay->copy()->addDay()->startOfDay();

        return BookingSegment::query()
            ->whereNotIn('status', ['cancelled', 'checked_out', 'completed'])
            ->where('check_in_at', '<', $dayEnd)
            ->where('check_out_at', '>', $dayStart)
            ->whereHas('booking', function ($q) {
                $q->where('status', '=', 'checked_in');
            })
            ->with(['booking', 'room.roomType'])
            ->orderBy('room_id')
            ->get()
            ->unique('room_id')
            ->values();
    }

    /**
     * Occupied rooms today whose daily cleaning status is still pending (housekeeping nav badge).
     */
    private function dailyCleaningPendingCountForNav(Carbon $serviceDay): int
    {
        $d = $serviceDay->toDateString();
        $segments = $this->dailyCleaningOccupiedSegments($serviceDay);
        $roomIds = $segments->pluck('room_id')->map(fn($id) => (int) $id)->all();
        if ($roomIds === []) {
            return 0;
        }

        $cleanings = DailyRoomCleaning::query()
            ->where('service_date', '=', $d)
            ->whereIn('room_id', $roomIds, 'and', false)
            ->get(['room_id', 'status'])
            ->keyBy('room_id');

        $n = 0;
        foreach ($segments as $seg) {
            $rid = (int) $seg->room_id;
            $effective = (string) ($cleanings->get($rid)?->status ?? 'pending_cleaning');
            if ($effective === 'pending_cleaning') {
                $n++;
            }
        }

        return $n;
    }

    /**
     * Daily occupied-room cleaning board: checked-in guests + today's cleaning row + consumptions.
     */
    public function dailyCleaningIndex(Request $request)
    {
        $this->allowHousekeepingViewSection([self::HK_DAILY]);

        $validated = $request->validate([
            'date' => 'nullable|date',
            'floor' => 'nullable|string|max:50',
            'room_type_id' => 'nullable|exists:room_types,id',
            'assigned_to' => 'nullable|exists:users,id',
            'assigned_null' => 'nullable|boolean',
            'status' => 'nullable|in:pending_cleaning,in_progress,cleaned,all',
        ]);

        $d = isset($validated['date'])
            ? Carbon::parse($validated['date'])->toDateString()
            : Carbon::today()->toDateString();

        $segments = $this->dailyCleaningOccupiedSegments(Carbon::parse($d));

        $segments = $segments->filter(function (BookingSegment $seg) use ($validated) {
            $room = $seg->room;
            if (! $room) {
                return false;
            }
            if (! empty($validated['floor']) && (string) ($room->floor ?? '') !== (string) $validated['floor']) {
                return false;
            }
            if (! empty($validated['room_type_id']) && (int) $room->room_type_id !== (int) $validated['room_type_id']) {
                return false;
            }

            return true;
        })->values();

        $roomIds = $segments->pluck('room_id')->map(fn($id) => (int) $id)->all();

        $cleanings = DailyRoomCleaning::query()
            ->where('service_date', '=', $d)
            ->whereIn('room_id', $roomIds, 'and', false)
            ->with([
                'consumptions.inventoryItem:id,name,sku',
                'assignedUser:id,name',
                'startedByUser:id,name',
                'completedByUser:id,name',
            ])
            ->get()
            ->keyBy('room_id');

        $statusFilter = $validated['status'] ?? 'pending_cleaning';

        $rows = [];
        foreach ($segments as $seg) {
            $room = $seg->room;
            $booking = $seg->booking;
            $cleaning = $cleanings->get((int) $seg->room_id);

            if ($statusFilter !== 'all' && ($cleaning?->status ?? 'pending_cleaning') !== $statusFilter) {
                continue;
            }
            if (! empty($validated['assigned_to'])) {
                $aid = (int) $validated['assigned_to'];
                if ((int) ($cleaning?->assigned_to ?? 0) !== $aid) {
                    continue;
                }
            } elseif ($request->boolean('assigned_null')) {
                if ($cleaning && $cleaning->assigned_to !== null) {
                    continue;
                }
            }

            $rows[] = [
                'segment_id' => (int) $seg->id,
                'room_id' => (int) $seg->room_id,
                'booking_id' => $booking ? (int) $booking->id : null,
                'guest_name' => $booking ? (string) ($booking->guest_name ?? '') : '',
                'room' => $room,
                'cleaning' => $cleaning,
                'effective_status' => $cleaning?->status ?? 'pending_cleaning',
            ];
        }

        return response()->json([
            'service_date' => $d,
            'rows' => $rows,
        ]);
    }

    /**
     * Update cleaning status, assignment, and notes for a room on a service date.
     */
    public function dailyCleaningUpdateStatus(Request $request)
    {
        $this->allowHousekeepingOperate([self::HK_DAILY]);

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'service_date' => 'required|date',
            'status' => 'required|in:pending_cleaning,in_progress,cleaned',
            'assigned_to' => 'nullable|exists:users,id',
            'remarks' => 'nullable|string|max:5000',
            'maintenance_note' => 'nullable|string|max:5000',
            'notify_front_desk' => 'nullable|boolean',
            'checklist_done' => 'nullable|array',
        ]);

        $userId = Auth::id();
        $d = Carbon::parse($validated['service_date'])->toDateString();
        $roomId = (int) $validated['room_id'];

        $segments = $this->dailyCleaningOccupiedSegments(Carbon::parse($d));
        $seg = $segments->firstWhere('room_id', '=', $roomId);
        if (! $seg) {
            return response()->json(['message' => 'Room is not occupied (checked-in) on this date.'], 422);
        }

        $bookingId = $seg->booking ? (int) $seg->booking->id : null;
        $room = Room::find($roomId, ['id', 'room_number']);

        DB::beginTransaction();
        try {
            /** @var DailyRoomCleaning $cleaning */
            $cleaning = DailyRoomCleaning::firstOrCreate(
                [
                    'room_id' => $roomId,
                    'service_date' => $d,
                ],
                [
                    'booking_id' => $bookingId,
                    'status' => 'pending_cleaning',
                ]
            );

            if ($bookingId && (int) ($cleaning->booking_id ?? 0) !== $bookingId) {
                $cleaning->booking_id = $bookingId;
            }

            $newStatus = (string) $validated['status'];
            $cleaning->status = $newStatus;

            if (array_key_exists('assigned_to', $validated)) {
                $cleaning->assigned_to = $validated['assigned_to'];
            }
            if (array_key_exists('remarks', $validated)) {
                $cleaning->remarks = $validated['remarks'];
            }
            if (array_key_exists('maintenance_note', $validated)) {
                $cleaning->maintenance_note = $validated['maintenance_note'];
            }
            if (array_key_exists('checklist_done', $validated)) {
                $cleaning->checklist_done = $this->sanitizeHousekeepingChecklistDone($validated['checklist_done'] ?? null);
            }

            if ($newStatus === 'pending_cleaning') {
                $cleaning->started_at = null;
                $cleaning->started_by = null;
                $cleaning->completed_at = null;
                $cleaning->completed_by = null;
            } elseif ($newStatus === 'in_progress') {
                if (! $cleaning->started_at) {
                    $cleaning->started_at = now();
                    $cleaning->started_by = $userId;
                }
                $cleaning->completed_at = null;
                $cleaning->completed_by = null;
            } elseif ($newStatus === 'cleaned') {
                if (! $cleaning->started_at) {
                    $cleaning->started_at = now();
                    $cleaning->started_by = $userId;
                }
                if (! $cleaning->completed_at) {
                    $cleaning->completed_at = now();
                    $cleaning->completed_by = $userId;
                }
            }

            $cleaning->save();
            DB::commit();

            HousekeepingStateUpdated::dispatchIfEnabled([$roomId], 'daily_cleaning_status');

            $notify = $request->boolean('notify_front_desk') && $newStatus === 'cleaned';
            if ($notify && ! $cleaning->front_desk_notified_at && config('broadcasting.default') !== 'null') {
                $guest = $seg->booking ? (string) ($seg->booking->guest_name ?? '') : '';
                $msg = 'Daily cleaning completed for room #' . (string) ($room?->room_number ?? $roomId);
                $rn = (string) ($room?->room_number ?? '');
                $guestBroadcast = $guest !== '' ? $guest : null;
                App::terminating(function () use ($roomId, $rn, $bookingId, $d, $msg, $guestBroadcast) {
                    try {
                        event(new DailyRoomCleaningDeskNotify(
                            $roomId,
                            $rn,
                            $bookingId,
                            $guestBroadcast,
                            $d,
                            $msg,
                        ));
                    } catch (\Throwable $e) {
                        report($e);
                    }
                });
                $cleaning->front_desk_notified_at = now();
                $cleaning->save();
            }

            return response()->json([
                'cleaning' => $cleaning->fresh()->load([
                    'consumptions.inventoryItem:id,name,sku',
                    'assignedUser:id,name',
                    'startedByUser:id,name',
                    'completedByUser:id,name',
                    'room:id,room_number,floor,room_type_id',
                ]),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Record complimentary / in-room consumables used during daily cleaning (deducts room store).
     */
    public function dailyCleaningRecordConsumption(Request $request)
    {
        $this->allowHousekeepingOperate([self::HK_DAILY]);

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'service_date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.inventory_item_id' => 'required|exists:inventory_items,id',
            'lines.*.qty' => 'required|numeric|min:0.001|max:99999',
            'lines.*.notes' => 'nullable|string|max:500',
            'checklist_done' => 'nullable|array',
        ]);

        $userId = Auth::id();
        $d = Carbon::parse($validated['service_date'])->toDateString();
        $roomId = (int) $validated['room_id'];

        $segments = $this->dailyCleaningOccupiedSegments(Carbon::parse($d));
        $seg = $segments->firstWhere('room_id', '=', $roomId);
        if (! $seg) {
            return response()->json(['message' => 'Room is not occupied (checked-in) on this date.'], 422);
        }

        $bookingId = $seg->booking ? (int) $seg->booking->id : null;

        $roomLoc = InventoryLocation::where('room_id', '=', $roomId, 'and')->first();
        if (! $roomLoc) {
            return response()->json(['message' => 'No inventory location is mapped to this room.'], 422);
        }

        DB::beginTransaction();
        try {
            /** @var DailyRoomCleaning $cleaning */
            $cleaning = DailyRoomCleaning::firstOrCreate(
                [
                    'room_id' => $roomId,
                    'service_date' => $d,
                ],
                [
                    'booking_id' => $bookingId,
                    'status' => 'pending_cleaning',
                ]
            );
            if ($bookingId && (int) ($cleaning->booking_id ?? 0) !== $bookingId) {
                $cleaning->booking_id = $bookingId;
                $cleaning->save();
            }

            $onHand = [];
            $locRows = DB::table('inventory_item_locations')
                ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                ->pluck('quantity', 'inventory_item_id');
            foreach ($locRows as $itemId => $qty) {
                $onHand[(int) $itemId] = (float) $qty;
            }

            $createdLines = [];
            foreach ($validated['lines'] as $ln) {
                $itemId = (int) $ln['inventory_item_id'];
                $qty = (float) $ln['qty'];
                $available = (float) ($onHand[$itemId] ?? 0);
                if ($qty > $available + 0.0001) {
                    DB::rollBack();

                    return response()->json([
                        'message' => 'Quantity exceeds on-hand stock in the room for item #' . $itemId . ' (max ' . round($available, 4) . ').',
                    ], 422);
                }

                DB::table('inventory_item_locations')->updateOrInsert(
                    ['inventory_item_id' => $itemId, 'inventory_location_id' => $roomLoc->id],
                    ['updated_at' => now(), 'created_at' => now()]
                );
                DB::table('inventory_item_locations')
                    ->where('inventory_item_id', '=', $itemId, 'and')
                    ->where('inventory_location_id', '=', $roomLoc->id, 'and')
                    ->decrement('quantity', $qty);

                /** @var InventoryItem|null $item */
                $item = InventoryItem::lockForUpdate()->find($itemId);
                $conv = max(1.0, (float) ($item?->conversion_factor ?: 1));
                $unitCost = (float) ($item?->cost_price ?? 0) / $conv;

                InventoryTransaction::create([
                    'inventory_item_id' => $itemId,
                    'inventory_location_id' => $roomLoc->id,
                    'type' => 'out',
                    'quantity' => $qty,
                    'unit_cost' => round($unitCost, 4),
                    'total_cost' => round($qty * $unitCost, 2),
                    'reason' => 'Daily room cleaning consumption',
                    'notes' => 'Occupied-room service — daily cleaning #' . $cleaning->id,
                    'user_id' => $userId,
                    'reference_id' => (string) $cleaning->id,
                    'reference_type' => 'daily_room_cleaning',
                ]);
                InventoryItem::syncStoredCurrentStockFromLocations($itemId);

                $onHand[$itemId] = $available - $qty;

                $row = DailyRoomCleaningConsumption::create([
                    'daily_room_cleaning_id' => $cleaning->id,
                    'inventory_item_id' => $itemId,
                    'qty' => $qty,
                    'notes' => $ln['notes'] ?? null,
                    'recorded_by' => $userId,
                ]);
                $createdLines[] = $row->load('inventoryItem:id,name,sku');
            }

            if (array_key_exists('checklist_done', $validated)) {
                $cleaning->checklist_done = $this->sanitizeHousekeepingChecklistDone($validated['checklist_done'] ?? null);
                $cleaning->save();
            }

            DB::commit();

            HousekeepingStateUpdated::dispatchIfEnabled([$roomId], 'daily_cleaning_consumption');

            return response()->json([
                'cleaning' => $cleaning->fresh()->load([
                    'consumptions.inventoryItem:id,name,sku',
                    'assignedUser:id,name',
                ]),
                'lines' => $createdLines,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Recent daily cleaning records for a room (audit / history).
     */
    public function dailyCleaningHistory(Request $request)
    {
        $this->allowHousekeepingViewSection([self::HK_DAILY]);

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'limit' => 'nullable|integer|min:1|max:90',
        ]);

        $limit = (int) ($validated['limit'] ?? 30);
        $roomId = (int) $validated['room_id'];

        $items = DailyRoomCleaning::query()
            ->where('room_id', '=', $roomId, 'and')
            ->with([
                'consumptions.inventoryItem:id,name,sku',
                'assignedUser:id,name',
                'startedByUser:id,name',
                'completedByUser:id,name',
            ])
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(['history' => $items]);
    }

    /**
     * Daily cleaning records for a service date (main daily board history panel).
     */
    public function dailyCleaningHistoryBoard(Request $request)
    {
        $this->allowHousekeepingViewSection([self::HK_DAILY]);

        $validated = $request->validate([
            'date' => 'required|date',
            'floor' => 'nullable|string|max:50',
            'room_type_id' => 'nullable|integer|exists:room_types,id',
            'status' => 'nullable|string|in:pending_cleaning,in_progress,cleaned,all',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $limit = (int) ($validated['limit'] ?? 100);
        $date = Carbon::parse($validated['date'])->toDateString();
        $status = (string) ($validated['status'] ?? 'all');

        $query = DailyRoomCleaning::query()
            ->whereDate('service_date', '=', $date, 'and')
            ->with([
                'room:id,room_number,floor,room_type_id',
                'room.roomType:id,name',
                'booking:id,first_name,last_name',
                'assignedUser:id,name',
                'startedByUser:id,name',
                'completedByUser:id,name',
            ])
            ->orderByDesc('completed_at')
            ->orderByDesc('id');

        if ($status !== '' && $status !== 'all') {
            $query->where('status', '=', $status, 'and');
        }
        if (! empty($validated['floor'])) {
            $query->whereHas('room', fn($q) => $q->where('floor', '=', $validated['floor'], 'and'));
        }
        if (! empty($validated['room_type_id'])) {
            $query->whereHas('room', fn($q) => $q->where('room_type_id', '=', (int) $validated['room_type_id'], 'and'));
        }

        $rows = $query->limit($limit)->get();

        $entries = $rows->map(fn(DailyRoomCleaning $r) => $this->dailyCleaningHistoryEntry($r, true))->values()->all();

        return response()->json([
            'service_date' => $date,
            'entries' => $entries,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyCleaningHistoryEntry(DailyRoomCleaning $r, bool $includeRoom = false): array
    {
        $dailyLabel = [
            'pending_cleaning' => 'Pending cleaning',
            'in_progress' => 'In progress',
            'cleaned' => 'Cleaned',
        ];

        $occ = $r->completed_at ?? $r->started_at ?? Carbon::parse($r->service_date)->endOfDay();
        $staff = $r->completedByUser?->name
            ?? $r->startedByUser?->name
            ?? $r->assignedUser?->name;

        $remarks = trim(implode("\n\n", array_filter([
            $r->remarks ? (string) $r->remarks : null,
            $r->maintenance_note ? 'Maintenance: ' . (string) $r->maintenance_note : null,
        ])));

        $entry = [
            'source' => 'daily_service',
            'record_id' => (int) $r->id,
            'room_id' => (int) $r->room_id,
            'source_label' => 'Daily service (occupied)',
            'occurred_at' => $occ instanceof Carbon ? $occ->toIso8601String() : $occ,
            'service_date' => $r->service_date instanceof Carbon ? $r->service_date->toDateString() : (string) $r->service_date,
            'cleaning_status' => $dailyLabel[$r->status] ?? $r->status,
            'staff_name' => $staff,
            'remarks' => $remarks !== '' ? $remarks : null,
            'inspection_status' => null,
        ];

        if ($includeRoom && $r->relationLoaded('room') && $r->room) {
            $entry['room_number'] = (string) $r->room->room_number;
            $entry['room_type_name'] = (string) ($r->room->roomType?->name ?? '');
            $entry['floor'] = $r->room->floor !== null ? (string) $r->room->floor : null;
        }

        if ($includeRoom && $r->relationLoaded('booking') && $r->booking) {
            $guest = trim(((string) ($r->booking->first_name ?? '')) . ' ' . ((string) ($r->booking->last_name ?? '')));
            $entry['guest_name'] = $guest !== '' ? $guest : null;
        }

        return $entry;
    }

    /**
     * Unified housekeeping timeline for a room (daily occupied service + turnover jobs).
     */
    public function roomCleaningHistory(Request $request, Room $room)
    {
        $this->allowHousekeepingNav();

        $limit = min(120, max(1, (int) $request->query('limit', 60)));

        $dailyRows = DailyRoomCleaning::query()
            ->where('room_id', '=', $room->id, 'and')
            ->with([
                'assignedUser:id,name',
                'startedByUser:id,name',
                'completedByUser:id,name',
            ])
            ->orderByDesc('service_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $dailyEntries = $dailyRows->map(function (DailyRoomCleaning $r) {
            return $this->dailyCleaningHistoryEntry($r, false);
        })->all();

        $jobs = HousekeepingJob::query()
            ->where('room_id', '=', $room->id, 'and')
            ->with([
                'block:id,status,is_active,start_date,end_date',
                'startedByUser:id,name',
                'finishedByUser:id,name',
            ])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $jobEntries = $jobs->map(function (HousekeepingJob $job) {
            $occ = $job->updated_at ?? $job->created_at;
            $staff = $job->finishedByUser?->name ?? $job->startedByUser?->name;
            $block = $job->block;
            $jobStatusLabel = match ($job->status) {
                'completed' => 'Completed',
                'inspected' => 'Awaiting supervisor release',
                'in_progress' => 'Cleaning in progress',
                default => (string) $job->status,
            };

            $inspection = match ($job->status) {
                'completed' => 'Supervisor released (room available)',
                'inspected' => $block && $block->is_active && $block->status === 'inspected'
                    ? 'Pending supervisor sign-off'
                    : ($block ? 'Turnover inspection stage' : null),
                default => null,
            };

            $remarks = trim(implode("\n\n", array_filter([
                $job->remarks ? (string) $job->remarks : null,
                $job->issues_summary ? 'Issues: ' . (string) $job->issues_summary : null,
            ])));

            return [
                'source' => 'turnover',
                'record_id' => (int) $job->id,
                'source_label' => 'Turnover / checkout clean',
                'occurred_at' => $occ instanceof Carbon ? $occ->toIso8601String() : (string) $occ,
                'service_date' => $occ instanceof Carbon ? $occ->toDateString() : Carbon::parse($job->created_at)->toDateString(),
                'cleaning_status' => $jobStatusLabel,
                'staff_name' => $staff,
                'remarks' => $remarks !== '' ? $remarks : null,
                'inspection_status' => $inspection,
            ];
        })->all();

        $merged = array_merge($dailyEntries, $jobEntries);
        usort($merged, function ($a, $b) {
            return strcmp((string) ($b['occurred_at'] ?? ''), (string) ($a['occurred_at'] ?? ''));
        });
        $merged = array_slice($merged, 0, $limit);

        return response()->json([
            'room_id' => (int) $room->id,
            'entries' => array_values($merged),
        ]);
    }

    /**
     * Full detail for one row from {@see roomCleaningHistory} (daily occupied service or turnover job).
     */
    public function roomCleaningHistoryDetail(Request $request, Room $room)
    {
        $this->allowHousekeepingNav();

        $validated = $request->validate([
            'source' => 'required|string|in:daily_service,turnover',
            'id' => 'required|integer|min:1',
        ]);

        $source = (string) $validated['source'];
        $id = (int) $validated['id'];

        if ($source === 'daily_service') {
            /** @var DailyRoomCleaning|null $record */
            $record = DailyRoomCleaning::query()
                ->where('room_id', '=', $room->id, 'and')
                ->where('id', '=', $id, 'and')
                ->with([
                    'consumptions.inventoryItem:id,name,sku',
                    'assignedUser:id,name',
                    'startedByUser:id,name',
                    'completedByUser:id,name',
                    'booking:id,first_name,last_name',
                ])
                ->first();

            if (! $record) {
                return response()->json(['message' => 'Cleaning record not found for this room.'], 404);
            }

            $dailyLabel = [
                'pending_cleaning' => 'Pending cleaning',
                'in_progress' => 'In progress',
                'cleaned' => 'Cleaned',
            ];

            $guestName = null;
            if ($record->booking) {
                $guestName = trim(((string) ($record->booking->first_name ?? '')) . ' ' . ((string) ($record->booking->last_name ?? '')));
                if ($guestName === '') {
                    $guestName = null;
                }
            }

            return response()->json([
                'source' => 'daily_service',
                'record_id' => (int) $record->id,
                'room_id' => (int) $record->room_id,
                'service_date' => $record->service_date instanceof Carbon
                    ? $record->service_date->toDateString()
                    : (string) $record->service_date,
                'status' => (string) $record->status,
                'status_label' => $dailyLabel[$record->status] ?? (string) $record->status,
                'started_at' => $record->started_at?->toIso8601String(),
                'completed_at' => $record->completed_at?->toIso8601String(),
                'assigned_user' => $record->assignedUser
                    ? ['id' => (int) $record->assignedUser->id, 'name' => (string) $record->assignedUser->name]
                    : null,
                'started_by_user' => $record->startedByUser
                    ? ['id' => (int) $record->startedByUser->id, 'name' => (string) $record->startedByUser->name]
                    : null,
                'completed_by_user' => $record->completedByUser
                    ? ['id' => (int) $record->completedByUser->id, 'name' => (string) $record->completedByUser->name]
                    : null,
                'guest_name' => $guestName,
                'booking_id' => $record->booking_id ? (int) $record->booking_id : null,
                'remarks' => $record->remarks ? (string) $record->remarks : null,
                'maintenance_note' => $record->maintenance_note ? (string) $record->maintenance_note : null,
                'checklist' => $this->formatDailyChecklistForDetail($record->checklist_done),
                'consumptions' => $record->consumptions->map(fn($c) => [
                    'id' => (int) $c->id,
                    'qty' => (float) $c->qty,
                    'notes' => $c->notes ? (string) $c->notes : null,
                    'inventory_item' => $c->inventoryItem ? [
                        'id' => (int) $c->inventoryItem->id,
                        'name' => (string) $c->inventoryItem->name,
                        'sku' => (string) ($c->inventoryItem->sku ?? ''),
                    ] : null,
                ])->values()->all(),
            ]);
        }

        /** @var HousekeepingJob|null $job */
        $job = HousekeepingJob::query()
            ->where('room_id', '=', $room->id, 'and')
            ->where('id', '=', $id, 'and')
            ->with([
                'block:id,status,is_active,start_date,end_date',
                'startedByUser:id,name',
                'finishedByUser:id,name',
                'lines.inventoryItem:id,name,sku',
                'lines.menuItem:id,name',
            ])
            ->first();

        if (! $job) {
            return response()->json(['message' => 'Cleaning record not found for this room.'], 404);
        }

        $jobStatusLabel = match ($job->status) {
            'completed' => 'Completed',
            'inspected' => 'Awaiting supervisor release',
            'in_progress' => 'Cleaning in progress',
            default => (string) $job->status,
        };

        $block = $job->block;
        $inspection = match ($job->status) {
            'completed' => 'Supervisor released (room available)',
            'inspected' => $block && $block->is_active && $block->status === 'inspected'
                ? 'Pending supervisor sign-off'
                : ($block ? 'Turnover inspection stage' : null),
            default => null,
        };

        $checklist = [];
        $amenities = [];
        $minibar = [];
        $assets = [];

        foreach ($job->lines as $line) {
            $kind = (string) $line->kind;
            $meta = is_array($line->meta) ? $line->meta : [];
            if ($kind === 'checklist') {
                $checklist[] = [
                    'key' => (string) ($meta['key'] ?? ''),
                    'label' => (string) ($meta['label'] ?? ($meta['key'] ?? 'Item')),
                    'done' => (bool) ($meta['done'] ?? false),
                ];
            } elseif ($kind === 'amenity') {
                $amenities[] = [
                    'inventory_item_id' => $line->inventory_item_id ? (int) $line->inventory_item_id : null,
                    'name' => (string) ($line->inventoryItem?->name ?? 'Amenity'),
                    'sku' => (string) ($line->inventoryItem?->sku ?? ''),
                    'qty' => (float) $line->qty,
                    'found_qty' => array_key_exists('found_qty', $meta) ? (float) $meta['found_qty'] : null,
                ];
            } elseif ($kind === 'minibar') {
                $minibar[] = [
                    'inventory_item_id' => $line->inventory_item_id ? (int) $line->inventory_item_id : null,
                    'menu_item_id' => $line->menu_item_id ? (int) $line->menu_item_id : null,
                    'name' => (string) ($line->inventoryItem?->name ?? $line->menuItem?->name ?? 'Minibar item'),
                    'sku' => (string) ($line->inventoryItem?->sku ?? ''),
                    'qty' => (float) $line->qty,
                    'found_qty' => array_key_exists('found_qty', $meta) ? (float) $meta['found_qty'] : null,
                ];
            } elseif ($kind === 'asset') {
                $assets[] = [
                    'key' => (string) ($meta['key'] ?? ''),
                    'label' => (string) ($meta['label'] ?? ($meta['key'] ?? 'Asset')),
                    'status' => (string) ($meta['status'] ?? 'ok'),
                    'note' => isset($meta['note']) ? (string) $meta['note'] : null,
                ];
            }
        }

        return response()->json([
            'source' => 'turnover',
            'record_id' => (int) $job->id,
            'room_id' => (int) $job->room_id,
            'status' => (string) $job->status,
            'status_label' => $jobStatusLabel,
            'started_at' => $job->created_at?->toIso8601String(),
            'completed_at' => $job->updated_at?->toIso8601String(),
            'started_by_user' => $job->startedByUser
                ? ['id' => (int) $job->startedByUser->id, 'name' => (string) $job->startedByUser->name]
                : null,
            'finished_by_user' => $job->finishedByUser
                ? ['id' => (int) $job->finishedByUser->id, 'name' => (string) $job->finishedByUser->name]
                : null,
            'remarks' => $job->remarks ? (string) $job->remarks : null,
            'issues_summary' => $job->issues_summary ? (string) $job->issues_summary : null,
            'inspection_status' => $inspection,
            'block' => $block ? [
                'start_date' => $block->start_date instanceof Carbon
                    ? $block->start_date->toDateString()
                    : (string) $block->start_date,
                'end_date' => $block->end_date instanceof Carbon
                    ? $block->end_date->toDateString()
                    : (string) $block->end_date,
                'status' => (string) $block->status,
                'is_active' => (bool) $block->is_active,
            ] : null,
            'checklist' => $checklist,
            'amenities' => $amenities,
            'minibar' => $minibar,
            'assets' => $assets,
        ]);
    }

    /**
     * @return array<int, array{key: string, label: string, done: bool}>
     */
    private function formatDailyChecklistForDetail(?array $saved): array
    {
        $saved = is_array($saved) ? $saved : [];
        $seen = [];
        $out = [];

        foreach ($this->housekeepingChecklistTemplate() as $item) {
            $key = (string) $item['key'];
            $seen[$key] = true;
            $out[] = [
                'key' => $key,
                'label' => (string) $item['label'],
                'done' => (bool) ($saved[$key] ?? false),
            ];
        }

        foreach ($saved as $key => $done) {
            $k = (string) $key;
            if (isset($seen[$k])) {
                continue;
            }
            $out[] = [
                'key' => $k,
                'label' => $k,
                'done' => (bool) $done,
            ];
        }

        return $out;
    }
}
