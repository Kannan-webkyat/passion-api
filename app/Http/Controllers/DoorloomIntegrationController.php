<?php

namespace App\Http\Controllers;

use App\Models\DoorloomIntegration;
use App\Models\DoorloomNight;
use App\Models\Room;
use App\Models\RoomType;
use App\Support\DoorloomClient;
use App\Support\DoorloomStaySync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DoorloomIntegrationController extends Controller
{
    private function checkSettings(): void
    {
        $user = Auth::user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can('manage-settings')) {
            abort(403, 'Unauthorized action.');
        }
    }

    private function canViewCalendar(): void
    {
        $user = Auth::user();
        if (! $user) {
            abort(403, 'Unauthorized action.');
        }
        if ($user->hasRole('Admin') || $user->hasRole('Super Admin')) {
            return;
        }
        foreach (['reservation-view', 'view-rooms', 'manage-rooms', 'rooms-view'] as $permission) {
            if ($user->can($permission)) {
                return;
            }
        }
        abort(403, 'Unauthorized action.');
    }

    public function status()
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('doorloom_integrations')) {
            return response()->json(['enabled' => false]);
        }

        return response()->json(['enabled' => (bool) DoorloomIntegration::current()->enabled]);
    }

    public function show()
    {
        $this->checkSettings();
        $integration = DoorloomIntegration::current();

        return response()->json($this->payload($integration));
    }

    public function update(Request $request)
    {
        $this->checkSettings();
        $validated = $request->validate([
            'enabled' => 'nullable|boolean',
            'api_key' => 'nullable|string|max:500',
            'webhook_secret' => 'nullable|string|max:500',
            'address_line_1' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pin_code' => 'nullable|string|max:20',
            'room_types' => 'nullable|array',
            'room_types.*.id' => 'required|integer|exists:room_types,id',
            'room_types.*.doorloom_property_id' => 'nullable|integer|min:1',
            'rooms' => 'nullable|array',
            'rooms.*.id' => 'required|integer|exists:rooms,id',
            'rooms.*.doorloom_inventory_id' => 'nullable|integer|min:1',
        ]);

        $integration = DoorloomIntegration::current();
        if (array_key_exists('enabled', $validated)) {
            $integration->enabled = (bool) $validated['enabled'];
        }
        foreach (['address_line_1', 'city', 'state', 'pin_code'] as $field) {
            if (array_key_exists($field, $validated)) {
                $integration->{$field} = trim((string) ($validated[$field] ?? '')) ?: null;
            }
        }
        if (! empty($validated['api_key'])) {
            $integration->api_key = trim($validated['api_key']);
        }
        if (! empty($validated['webhook_secret'])) {
            $integration->webhook_secret = trim($validated['webhook_secret']);
        }
        $integration->save();

        if (! empty($validated['api_key']) && $integration->ready()) {
            $me = DoorloomClient::me();
            $name = data_get($me, 'json.data.name') ?: data_get($me, 'json.data.slug');
            if (is_string($name) && $name !== '') {
                $integration->integration_name = $name;
                $integration->save();
            }
        }

        foreach ($validated['room_types'] ?? [] as $row) {
            RoomType::query()->whereKey($row['id'])->update([
                'doorloom_property_id' => $row['doorloom_property_id'] ?? null,
            ]);
        }
        foreach ($validated['rooms'] ?? [] as $row) {
            Room::query()->whereKey($row['id'])->update([
                'doorloom_inventory_id' => $row['doorloom_inventory_id'] ?? null,
            ]);
        }

        return response()->json($this->payload($integration->fresh()));
    }

    public function pushListings()
    {
        $this->checkSettings();
        $result = DoorloomStaySync::pushListings();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function catchUp()
    {
        $this->checkSettings();
        $result = DoorloomStaySync::catchUp();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function fullSync()
    {
        $this->checkSettings();
        $result = DoorloomStaySync::fullSync();

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function calendar(Request $request)
    {
        $this->canViewCalendar();
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'room_type_id' => 'nullable|integer|exists:room_types,id',
        ]);

        $query = DoorloomNight::query()
            ->whereDate('night_date', '>=', $validated['from'])
            ->whereDate('night_date', '<=', $validated['to'])
            ->orderBy('night_date');
        if (! empty($validated['room_type_id'])) {
            $query->where('room_type_id', $validated['room_type_id']);
        }

        $nights = $query->get();
        $shortfall = $nights->contains(function (DoorloomNight $night) {
            return $night->stop_sell || ($night->available_units !== null && (int) $night->available_units === 0);
        });

        return response()->json([
            'room_types' => RoomType::query()->orderBy('name')->get(['id', 'name', 'doorloom_property_id']),
            'nights' => $nights,
            'shortfall' => $shortfall,
        ]);
    }

    private function payload(DoorloomIntegration $integration): array
    {
        return [
            'enabled' => (bool) $integration->enabled,
            'api_key_set' => trim((string) $integration->api_key) !== '',
            'webhook_secret_set' => trim((string) $integration->webhook_secret) !== '',
            'integration_name' => $integration->integration_name,
            'highest_sequence' => (int) $integration->highest_sequence,
            'last_full_sync_at' => $integration->last_full_sync_at?->toIso8601String(),
            'address_line_1' => $integration->address_line_1,
            'city' => $integration->city,
            'state' => $integration->state,
            'pin_code' => $integration->pin_code,
            'webhook_url' => rtrim((string) config('app.url'), '/').'/api/doorloom/webhook',
            'room_types' => RoomType::query()->with(['rooms' => function ($q) {
                $q->orderBy('room_number');
            }])->orderBy('name')->get()->map(function (RoomType $type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'doorloom_property_id' => $type->doorloom_property_id,
                    'rooms' => $type->rooms->map(fn (Room $room) => [
                        'id' => $room->id,
                        'room_number' => $room->room_number,
                        'doorloom_inventory_id' => $room->doorloom_inventory_id,
                    ])->values(),
                ];
            })->values(),
        ];
    }
}
