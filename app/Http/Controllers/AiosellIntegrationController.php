<?php

namespace App\Http\Controllers;

use App\Models\AiosellBookingLink;
use App\Models\AiosellIntegration;
use App\Models\AiosellMessage;
use App\Models\AiosellRatePlanMap;
use App\Models\AiosellRoomMap;
use App\Models\Booking;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Support\AiosellClient;
use App\Support\AiosellInventorySync;
use App\Support\AiosellMapping;
use App\Support\AiosellReservationWriter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AiosellIntegrationController extends Controller
{
    private function checkSettings(): void
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can('manage-settings')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function canViewReservation(): void
    {
        $user = Auth::user();
        if ($user && (
            $user->hasRole('Admin')
            || $user->can('reservation-view')
            || $user->can('reservation')
            || $user->can('reservation-edit')
            || $user->can('manage-settings')
        )) {
            return;
        }
        abort(403, 'Unauthorized action.');
    }

    private function canEditReservation(): void
    {
        $user = Auth::user();
        if ($user && (
            $user->hasRole('Admin')
            || $user->can('reservation-edit')
            || $user->can('reservation')
            || $user->can('manage-settings')
        )) {
            return;
        }
        abort(403, 'Unauthorized action.');
    }

    public function status(Request $request)
    {
        $validated = $request->validate([
            'room_type_id' => 'nullable|integer',
        ]);
        if (! \Illuminate\Support\Facades\Schema::hasTable('aiosell_integrations')) {
            return response()->json(['enabled' => false, 'ready' => false, 'room_type_mapped' => false]);
        }
        $integration = AiosellIntegration::current();
        $roomTypeId = (int) ($validated['room_type_id'] ?? 0);
        $mapped = $roomTypeId > 0 && AiosellRatePlanMap::query()
            ->where('active', true)
            ->where('room_type_id', $roomTypeId)
            ->whereNotNull('rate_plan_id')
            ->exists();

        return response()->json([
            'enabled' => (bool) $integration->enabled,
            'ready' => $integration->ready(),
            'room_type_mapped' => $mapped,
        ]);
    }

    public function show()
    {
        $this->checkSettings();

        return response()->json($this->payload(AiosellIntegration::current()));
    }

    public function update(Request $request)
    {
        $this->checkSettings();
        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:255',
            'partner_id' => 'nullable|string|max:100',
            'hotel_code' => 'nullable|string|max:100',
            'room_maps' => 'nullable|array',
            'room_maps.*.id' => 'required|integer|exists:aiosell_room_maps,id',
            'room_maps.*.room_type_id' => 'nullable|integer|exists:room_types,id',
            'room_maps.*.active' => 'nullable|boolean',
            'rate_plan_maps' => 'nullable|array',
            'rate_plan_maps.*.id' => 'required|integer|exists:aiosell_rate_plan_maps,id',
            'rate_plan_maps.*.room_type_id' => 'nullable|integer|exists:room_types,id',
            'rate_plan_maps.*.rate_plan_id' => 'nullable|integer|exists:rate_plans,id',
            'rate_plan_maps.*.price_override' => 'nullable|numeric|min:0',
            'rate_plan_maps.*.active' => 'nullable|boolean',
        ]);

        $integration = AiosellIntegration::current();
        $wasEnabled = (bool) $integration->enabled;
        if (array_key_exists('enabled', $validated)) {
            $integration->enabled = (bool) $validated['enabled'];
        }
        foreach (['username', 'password', 'partner_id', 'hotel_code'] as $field) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }
            $value = trim((string) ($validated[$field] ?? ''));
            if ($field === 'username' || $field === 'password') {
                if ($value !== '') {
                    $integration->{$field} = $value;
                }
                continue;
            }
            $integration->{$field} = $value !== '' ? $value : null;
        }
        $integration->save();

        foreach ($validated['room_maps'] ?? [] as $row) {
            AiosellRoomMap::query()->whereKey($row['id'])->update([
                'room_type_id' => $row['room_type_id'] ?? null,
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
            ]);
        }
        foreach ($validated['rate_plan_maps'] ?? [] as $row) {
            $override = $row['price_override'] ?? null;
            AiosellRatePlanMap::query()->whereKey($row['id'])->update([
                'room_type_id' => $row['room_type_id'] ?? null,
                'rate_plan_id' => $row['rate_plan_id'] ?? null,
                'price_override' => $override === null || $override === '' ? null : round((float) $override, 2),
                'active' => array_key_exists('active', $row) ? (bool) $row['active'] : true,
            ]);
        }

        $integration = $integration->fresh();
        $mappingSaved = isset($validated['room_maps']) || isset($validated['rate_plan_maps']);
        $turnedOn = ! $wasEnabled && (bool) $integration->enabled;
        if (($mappingSaved || $turnedOn) && $integration->ready()) {
            AiosellInventorySync::pushNow();
            $integration = $integration->fresh();
        }

        return response()->json($this->payload($integration));
    }

    public function loadMapping()
    {
        $this->checkSettings();
        $result = AiosellMapping::pull();

        return response()->json($result + ['integration' => $this->payload(AiosellIntegration::current()->fresh())], $result['ok'] ? 200 : 422);
    }

    public function pushNow()
    {
        $this->checkSettings();
        $result = AiosellInventorySync::pushNow();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function catchUp(Request $request)
    {
        $this->checkSettings();
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);
        $start = $validated['start_date'] ?? Carbon::today()->toDateString();
        $end = $validated['end_date'] ?? Carbon::today()->addDays(30)->toDateString();
        if ($end < $start) {
            return response()->json(['message' => 'End date must be on or after the start date.'], 422);
        }

        $fetched = AiosellClient::fetch('reservation', $start, $end);
        if (! $fetched['ok']) {
            return response()->json(['ok' => false, 'message' => $fetched['message'] ?: 'Could not fetch reservations.'], 422);
        }

        $items = array_is_list($fetched['json']) ? $fetched['json'] : [];
        $applied = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            unset($item['creditCard']);
            if (trim((string) ($item['action'] ?? '')) === '') {
                $item['action'] = 'book';
            }
            $result = AiosellReservationWriter::apply($item);
            if (! $result['ok']) {
                return response()->json(['ok' => false, 'message' => $result['message'], 'applied' => $applied], 422);
            }
            $applied++;
        }

        return response()->json(['ok' => true, 'message' => 'Reservations fetched.', 'applied' => $applied]);
    }

    public function restrictions(Request $request)
    {
        $this->checkSettings();
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'room_type_id' => 'required|integer|exists:room_types,id',
            'rate_plan_map_id' => 'nullable|integer|exists:aiosell_rate_plan_maps,id',
            'channels' => 'required|array|min:1',
            'channels.*' => 'required|string|max:100',
            'stop_sell' => 'nullable|boolean',
            'minimum_stay' => 'nullable|integer|min:0',
            'maximum_stay' => 'nullable|integer|min:0',
            'close_on_arrival' => 'nullable|boolean',
            'close_on_departure' => 'nullable|boolean',
            'exact_stay_arrival' => 'nullable|integer|min:0',
            'minimum_advance_reservation' => 'nullable|integer|min:0',
            'maximum_advance_reservation' => 'nullable|integer|min:0',
        ]);

        $roomMap = AiosellRoomMap::query()
            ->where('room_type_id', $validated['room_type_id'])
            ->where('active', true)
            ->first();
        if (! $roomMap) {
            return response()->json(['message' => 'That room type is not mapped to an AioSell room code.'], 422);
        }

        $rateCode = null;
        if (! empty($validated['rate_plan_map_id'])) {
            $rateMap = AiosellRatePlanMap::query()->whereKey($validated['rate_plan_map_id'])->where('active', true)->first();
            if (! $rateMap || (int) $rateMap->room_type_id !== (int) $validated['room_type_id']) {
                return response()->json(['message' => 'That rate plan is not mapped on this room type.'], 422);
            }
            $rateCode = $rateMap->rateplan_code;
        }

        $restrictions = array_filter([
            'stopSell' => array_key_exists('stop_sell', $validated) ? (bool) $validated['stop_sell'] : null,
            'minimumStay' => $validated['minimum_stay'] ?? null,
            'maximumStay' => $validated['maximum_stay'] ?? null,
            'closeOnArrival' => array_key_exists('close_on_arrival', $validated) ? (bool) $validated['close_on_arrival'] : null,
            'closeOnDeparture' => array_key_exists('close_on_departure', $validated) ? (bool) $validated['close_on_departure'] : null,
            'exactStayArrival' => $validated['exact_stay_arrival'] ?? null,
            'minimumAdvanceReservation' => $validated['minimum_advance_reservation'] ?? null,
            'maximumAdvanceReservation' => $validated['maximum_advance_reservation'] ?? null,
        ], fn ($value) => $value !== null);
        if ($restrictions === []) {
            return response()->json(['message' => 'Choose at least one restriction.'], 422);
        }

        $result = AiosellInventorySync::pushRestrictions(
            $validated['start_date'],
            $validated['end_date'],
            $roomMap->room_code,
            $rateCode,
            $validated['channels'],
            $restrictions,
        );

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function multiplier(Request $request)
    {
        $this->checkSettings();
        $validated = $request->validate([
            'multiplier' => 'required|numeric|min:0.01',
            'channels' => 'required|array|min:1',
            'channels.*' => 'required|string|max:100',
        ]);

        $result = AiosellClient::channelMultiplier([
            'hotelCode' => (string) AiosellClient::integration()->hotel_code,
            'multiplier' => (float) $validated['multiplier'],
            'channels' => array_values($validated['channels']),
        ]);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['message'] ?: ($result['ok'] ? 'Multiplier updated.' : 'Multiplier was not updated.'),
        ], $result['ok'] ? 200 : 422);
    }

    public function messages(Booking $booking)
    {
        $this->canViewReservation();
        $link = AiosellBookingLink::query()->where('passion_booking_id', $booking->id)->first();
        $messages = AiosellMessage::query()
            ->where(function ($query) use ($booking, $link) {
                $query->where('passion_booking_id', $booking->id);
                if ($link) {
                    $query->orWhere(function ($inner) use ($link) {
                        $inner->where('channel', $link->channel)->where('booking_id', $link->booking_id);
                    });
                }
            })
            ->orderBy('id')
            ->get();

        return response()->json([
            'linked' => $link !== null,
            'channel' => $link?->channel,
            'booking_id' => $link?->booking_id,
            'pah' => $link ? (bool) $link->pah : null,
            'can_no_show' => $link ? in_array(strtolower((string) $link->channel), ['booking.com', 'gommt'], true) : false,
            'messages' => $messages->map(fn (AiosellMessage $message) => [
                'id' => $message->id,
                'message_id' => $message->message_id,
                'conversation_id' => $message->conversation_id,
                'channel' => $message->channel,
                'sender_type' => $message->sender_type,
                'content' => $message->content,
                'time_sent' => $message->time_sent,
                'guest_name' => $message->guest_name,
            ])->values(),
        ]);
    }

    public function reply(Request $request, Booking $booking)
    {
        $this->canEditReservation();
        $validated = $request->validate([
            'content' => 'required|string|max:4000',
            'conversation_id' => 'nullable|string|max:191',
        ]);
        $link = AiosellBookingLink::query()->where('passion_booking_id', $booking->id)->first();
        $conversationId = trim((string) ($validated['conversation_id'] ?? ''));
        if ($conversationId === '') {
            $conversationId = (string) AiosellMessage::query()
                ->where('passion_booking_id', $booking->id)
                ->orderByDesc('id')
                ->value('conversation_id');
        }
        if ($conversationId === '') {
            return response()->json(['message' => 'This booking has no AioSell conversation.'], 422);
        }
        $channel = (string) ($link?->channel ?: AiosellMessage::query()->where('conversation_id', $conversationId)->value('channel'));
        if ($channel === '') {
            return response()->json(['message' => 'This conversation has no channel.'], 422);
        }

        $body = [
            'channel' => $channel,
            'content' => $validated['content'],
            'hotel_code' => (string) AiosellClient::integration()->hotel_code,
            'conversation_id' => $conversationId,
        ];
        if (strtolower($channel) === 'booking.com' && $link) {
            $body['booking_id'] = $link->booking_id;
        }
        $result = AiosellClient::messageReply($body);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message'] ?: 'Reply was not sent.'], 422);
        }

        AiosellMessage::query()->create([
            'message_id' => 'reply-'.Str::uuid(),
            'conversation_id' => $conversationId,
            'channel' => $channel,
            'booking_id' => $link?->booking_id,
            'passion_booking_id' => $booking->id,
            'sender_type' => 'property',
            'content' => $validated['content'],
            'time_sent' => now()->format('Y-m-d H:i:s'),
        ]);

        return response()->json(['ok' => true, 'message' => $result['message'] ?: 'Reply sent.']);
    }

    public function noShow(Booking $booking)
    {
        $this->canEditReservation();
        $link = AiosellBookingLink::query()->where('passion_booking_id', $booking->id)->first();
        if (! $link || ! in_array(strtolower((string) $link->channel), ['booking.com', 'gommt'], true)) {
            return response()->json(['message' => 'No-show is only available for Booking.com and Goibibo / MakeMyTrip stays.'], 422);
        }

        $result = AiosellClient::markNoShow([
            'hotelCode' => (string) AiosellClient::integration()->hotel_code,
            'bookingId' => $link->booking_id,
            'channel' => $link->channel,
        ]);
        if (! $result['ok']) {
            return response()->json(['message' => $result['message'] ?: 'No-show was not marked.'], 422);
        }

        $audit = '[No-show: AioSell '.$link->channel.' '.$link->booking_id.' on '.now()->format('Y-m-d H:i:s').']';
        $notes = trim((string) $booking->notes);
        $booking->notes = $notes !== '' ? $notes."\n".$audit : $audit;
        $booking->save();

        return response()->json(['ok' => true, 'message' => $result['message'] ?: 'Noshow Marked Successfully']);
    }

    private function payload(AiosellIntegration $integration): array
    {
        return [
            'enabled' => (bool) $integration->enabled,
            'username' => (string) $integration->username,
            'username_set' => trim((string) $integration->username) !== '',
            'password_set' => trim((string) $integration->password) !== '',
            'partner_id' => $integration->partner_id,
            'hotel_code' => $integration->hotel_code,
            'last_error' => $integration->last_error,
            'inventory_dirty' => (bool) $integration->inventory_dirty,
            'rates_pending_room_types' => RoomType::query()
                ->whereIn('id', array_map('intval', $integration->rates_pending_room_type_ids ?? []))
                ->orderBy('name')
                ->get(['id', 'name']),
            'connected_channels' => $integration->connected_channels ?? [],
            'ready' => $integration->ready(),
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/aiosell/webhook',
            'message_webhook_url' => rtrim((string) config('app.url'), '/').'/api/aiosell/messages',
            'room_maps' => AiosellRoomMap::query()->orderBy('room_code')->get(),
            'rate_plan_maps' => AiosellRatePlanMap::query()->orderBy('rateplan_code')->get(),
            'room_types' => RoomType::query()->orderBy('name')->get(['id', 'name']),
            'rate_plans' => RatePlan::query()->orderBy('name')->get(['id', 'name', 'room_type_id', 'meal_plan_type', 'billing_unit', 'base_price', 'is_active']),
        ];
    }
}
