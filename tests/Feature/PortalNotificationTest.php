<?php

namespace Tests\Feature;

use App\Events\PortalNotificationCreated;
use App\Models\Booking;
use App\Models\BookingSegment;
use App\Models\PortalNotification;
use App\Models\RoomCleaningRelease;
use App\Models\RoomStatusBlock;
use App\Support\PortalNotifications;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesHousekeepingFixtures;
use Tests\TestCase;

class PortalNotificationTest extends TestCase
{
    use CreatesHousekeepingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migratePortalNotificationTables();
    }

    public function test_notifications_require_a_front_desk_permission(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();

        $user = $this->createUserWithPermission('housekeeping-room-stock');
        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')->assertForbidden();
    }

    public function test_feed_is_shared_and_read_state_is_per_user(): void
    {
        $desk = $this->createUserWithPermission('view-rooms');
        $other = $this->createUserWithPermission('reservation-view');

        $id = PortalNotifications::recordDailyCleaning(
            12,
            '204',
            88,
            'Asha Rao',
            '2026-10-07',
            'Daily cleaning completed for room #204',
        );

        $this->assertNotNull($id);

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.id', $id)
            ->assertJsonPath('notifications.0.kind', 'daily_cleaning.desk_notify')
            ->assertJsonPath('notifications.0.title', 'Room 204 cleaned')
            ->assertJsonPath('notifications.0.room_number', '204')
            ->assertJsonPath('notifications.0.booking_id', 88)
            ->assertJsonPath('notifications.0.guest_name', 'Asha Rao')
            ->assertJsonPath('notifications.0.audience', 'front_desk')
            ->assertJsonPath('notifications.0.href', null)
            ->assertJsonPath('notifications.0.read_at', null);

        $this->postJson("/api/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonPath('unread_count', 0);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        Sanctum::actingAs($other);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.read_at', null);

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notifications.0.guest_name', 'Asha Rao');

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')->assertJsonPath('unread_count', 0);
    }

    public function test_only_read_notifications_are_cleared_and_only_for_that_user(): void
    {
        $desk = $this->createUserWithPermission('view-rooms');
        $other = $this->createUserWithPermission('reservation-view');

        $first = PortalNotifications::recordDailyCleaning(12, '204', 88, 'Asha Rao', '2026-10-07', 'Daily cleaning completed for room #204');
        $second = PortalNotifications::recordDailyCleaning(13, '205', 89, 'Ravi Nair', '2026-10-07', 'Daily cleaning completed for room #205');
        $this->assertNotNull($first);
        $this->assertNotNull($second);

        Sanctum::actingAs($desk);
        $this->postJson("/api/notifications/{$second}/clear")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Only read notifications can be cleared.');

        $this->postJson("/api/notifications/{$second}/read")->assertOk();
        $this->postJson("/api/notifications/{$second}/clear")
            ->assertOk()
            ->assertJsonPath('id', $second)
            ->assertJsonPath('unread_count', 1);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $first);

        Sanctum::actingAs($other);
        $this->postJson("/api/notifications/{$first}/read")->assertOk();
        $this->postJson('/api/notifications/clear-read')
            ->assertOk()
            ->assertJsonPath('cleared', 1)
            ->assertJsonPath('unread_count', 1);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $second)
            ->assertJsonPath('notifications.0.read_at', null);

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $first);
        $this->postJson('/api/notifications/999/clear')->assertNotFound();
    }

    public function test_live_event_names_the_actor_and_the_link(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([PortalNotificationCreated::class]);
        $actor = $this->createUserWithPermission('housekeeping-dirty-rooms');

        $id = PortalNotifications::record(
            PortalNotification::AUDIENCE_DIRTY_ROOMS,
            PortalNotification::KIND_DIRTY_ROOM,
            'Room 101 needs cleaning',
            'Room 101 is dirty and waiting for housekeeping.',
            ['room_id' => 5, 'room_number' => '101', 'booking_id' => 44],
            PortalNotifications::HREF_DIRTY_ROOMS,
            (int) $actor->id,
        );
        $this->app->terminate();

        Event::assertDispatched(PortalNotificationCreated::class, function (PortalNotificationCreated $event) use ($id, $actor) {
            $payload = $event->broadcastWith();

            return $payload['id'] === $id
                && $payload['actor_user_id'] === (int) $actor->id
                && $payload['href'] === PortalNotifications::HREF_DIRTY_ROOMS
                && $payload['booking_id'] === 44
                && $payload['room_id'] === 5;
        });
    }

    public function test_turnover_approval_notifies_the_front_desk_with_the_approver_and_room_chart_link(): void
    {
        config(['broadcasting.default' => 'log']);
        Event::fake([PortalNotificationCreated::class]);
        $room = $this->createRoom('207');
        $supervisor = $this->createUserWithPermission('housekeeping-supervisor-inspection');
        $supervisor->forceFill(['name' => 'Asha Supervisor'])->save();
        $desk = $this->createUserWithPermission('reservation-view');

        $id = PortalNotifications::recordRoomReady((int) $room->id, (int) $supervisor->id);
        $this->app->terminate();

        Event::assertDispatched(PortalNotificationCreated::class, function (PortalNotificationCreated $event) use ($id, $supervisor) {
            $payload = $event->broadcastWith();

            return $payload['id'] === $id
                && $payload['audience'] === PortalNotification::AUDIENCE_FRONT_DESK
                && $payload['actor_user_id'] === (int) $supervisor->id
                && $payload['href'] === PortalNotifications::HREF_ROOM_CHART;
        });

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'Room 207 is ready')
            ->assertJsonPath('notifications.0.message', 'Asha Supervisor approved the cleaning. Room 207 is available for check-in.')
            ->assertJsonPath('notifications.0.href', PortalNotifications::HREF_ROOM_CHART);

        Sanctum::actingAs($supervisor);
        $this->getJson('/api/notifications')->assertForbidden();
    }

    public function test_missing_notification_is_not_found(): void
    {
        $desk = $this->createUserWithPermission('reservation');
        Sanctum::actingAs($desk);

        $this->postJson('/api/notifications/999/read')->assertNotFound();
    }

    public function test_feed_is_limited_to_the_user_audience(): void
    {
        $desk = $this->createUserWithPermission('view-rooms');
        $housekeeping = $this->createUserWithPermission('housekeeping-dirty-rooms');
        $otherHousekeeping = $this->createUserWithPermission('housekeeping-cleaning-tasks');
        $allocator = $this->createUserWithPermission('housekeeping-assignable');

        $deskId = PortalNotifications::recordDailyCleaning(
            12,
            '204',
            88,
            'Asha Rao',
            '2026-10-07',
            'Daily cleaning completed for room #204',
        );
        $dirtyId = PortalNotifications::record(
            PortalNotification::AUDIENCE_DIRTY_ROOMS,
            PortalNotification::KIND_DIRTY_ROOM,
            'Room 101 needs cleaning',
            'Room 101 is dirty and waiting for housekeeping.',
            ['room_id' => 5, 'room_number' => '101'],
            PortalNotifications::HREF_DIRTY_ROOMS,
            (int) $housekeeping->id,
        );

        $this->assertNotNull($deskId);
        $this->assertNotNull($dirtyId);

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.id', $deskId)
            ->assertJsonPath('notifications.0.audience', 'front_desk');
        $this->postJson("/api/notifications/{$dirtyId}/read")->assertNotFound();

        Sanctum::actingAs($housekeeping);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notifications', []);

        Sanctum::actingAs($otherHousekeeping);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonPath('notifications', []);

        Sanctum::actingAs($allocator);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.id', $dirtyId)
            ->assertJsonPath('notifications.0.href', PortalNotifications::HREF_DIRTY_ROOMS);

        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('unread_count', 0);

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')->assertJsonPath('unread_count', 1);
    }

    public function test_reschedule_from_the_room_chart_notifies_the_front_desk(): void
    {
        $releaser = $this->createUserWithPermission('housekeeping-cleaning-availability');
        $attendant = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $desk = $this->createUserWithPermission('view-rooms');
        $room = $this->createRoom('201');
        $release = $this->createActiveRelease($room);

        Sanctum::actingAs($releaser);
        $this->postJson(
            "/api/housekeeping/cleaning-releases/{$release->id}/reschedule",
            $this->releaseWindowPayload(),
        )->assertOk();

        $this->assertDatabaseHas('portal_notifications', [
            'audience' => 'front_desk',
            'kind' => 'daily_cleaning.released',
            'title' => 'Room 201 released for cleaning',
        ]);
        $this->assertDatabaseHas('portal_notification_reads', [
            'user_id' => $releaser->id,
        ]);

        Sanctum::actingAs($attendant);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        Sanctum::actingAs($desk);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.kind', 'daily_cleaning.released')
            ->assertJsonPath('notifications.0.href', null);
    }

    public function test_release_with_staff_notifies_only_the_assignee(): void
    {
        $releaser = $this->createUserWithPermission('housekeeping-cleaning-availability');
        $this->ensurePermission('housekeeping-assignable');
        $releaser->givePermissionTo('housekeeping-assignable');
        $this->resetPermissionCache();
        $assignee = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $otherAttendant = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $room = $this->createRoom('201');

        Sanctum::actingAs($releaser);
        $this->postJson('/api/housekeeping/cleaning-releases', [
            'room_id' => $room->id,
            'assigned_to' => $assignee->id,
            ...$this->releaseWindowPayload(),
        ])->assertCreated();

        $this->assertDatabaseHas('portal_notifications', [
            'audience' => 'daily_cleaning',
            'title' => 'Room 201 assigned to you',
            'message' => 'You have been assigned daily cleaning for Room 201.',
            'recipient_user_id' => $assignee->id,
        ]);

        Sanctum::actingAs($assignee);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'Room 201 assigned to you')
            ->assertJsonPath('notifications.0.href', '/reception/housekeeping/daily-room-cleaning');

        Sanctum::actingAs($otherAttendant);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }

    public function test_reservice_request_is_for_daily_cleaning_and_approval_is_for_the_front_desk(): void
    {
        $releaser = $this->createUserWithPermission('housekeeping-cleaning-availability');
        $supervisor = $this->createUserWithPermission('housekeeping-supervisor-inspection');
        $attendant = $this->createUserWithPermission('housekeeping-daily-room-cleaning');
        $allocator = $this->createUserWithPermission('housekeeping-assignable');
        $desk = $this->createUserWithPermission('view-rooms');
        $room = $this->createRoom('201');

        Sanctum::actingAs($releaser);
        $created = $this->postJson('/api/housekeeping/cleaning-releases', [
            'room_id' => $room->id,
            ...$this->releaseWindowPayload(),
            'service_type' => 'other',
            'service_subtype' => 'rerelease',
            'is_rerelease' => true,
        ])->assertCreated();

        $releaseId = (int) $created->json('id');

        $this->assertDatabaseHas('portal_notifications', [
            'audience' => 'front_desk',
            'kind' => 'daily_cleaning.released',
            'title' => 'Room 201 released for cleaning',
        ]);
        $this->assertDatabaseHas('portal_notifications', [
            'audience' => 'daily_cleaning',
            'kind' => 'daily_cleaning.reservice_requested',
            'title' => 'Re-service requested for Room 201',
            'message' => 'Requested re-service',
        ]);
        $this->assertDatabaseHas('portal_notification_reads', [
            'user_id' => $releaser->id,
        ]);

        $release = RoomCleaningRelease::query()->findOrFail($releaseId);
        $release->status = RoomCleaningRelease::STATUS_INSPECTION_PENDING;
        $release->save();

        Sanctum::actingAs($supervisor);
        $this->postJson("/api/housekeeping/cleaning-releases/{$releaseId}/mark-inspected")
            ->assertOk();

        $this->assertDatabaseHas('portal_notifications', [
            'audience' => 'front_desk',
            'kind' => 'daily_cleaning.reservice_approved',
            'title' => 'Re-service approved for Room 201',
        ]);

        Sanctum::actingAs($attendant);
        $attendantFeed = $this->getJson('/api/notifications')->assertOk()->json();
        $attendantKinds = array_column($attendantFeed['notifications'], 'kind');
        $this->assertNotContains('daily_cleaning.reservice_requested', $attendantKinds);
        $this->assertNotContains('daily_cleaning.reservice_approved', $attendantKinds);
        $this->assertNotContains('daily_cleaning.released', $attendantKinds);
        $this->assertSame(0, $attendantFeed['unread_count']);

        Sanctum::actingAs($allocator);
        $allocatorFeed = $this->getJson('/api/notifications')->assertOk()->json();
        $allocatorKinds = array_column($allocatorFeed['notifications'], 'kind');
        $this->assertContains('daily_cleaning.reservice_requested', $allocatorKinds);
        $this->assertNotContains('daily_cleaning.reservice_approved', $allocatorKinds);
        $this->assertNotContains('daily_cleaning.released', $allocatorKinds);
        $requested = collect($allocatorFeed['notifications'])
            ->firstWhere('kind', 'daily_cleaning.reservice_requested');
        $this->assertSame('/reception/housekeeping/daily-room-cleaning', $requested['href']);
        $this->assertNull($requested['read_at']);

        Sanctum::actingAs($desk);
        $deskFeed = $this->getJson('/api/notifications')->assertOk()->json();
        $deskKinds = array_column($deskFeed['notifications'], 'kind');
        $this->assertContains('daily_cleaning.reservice_approved', $deskKinds);
        $this->assertContains('daily_cleaning.released', $deskKinds);
        $this->assertNotContains('daily_cleaning.reservice_requested', $deskKinds);
        $approved = collect($deskFeed['notifications'])
            ->firstWhere('kind', 'daily_cleaning.reservice_approved');
        $this->assertNull($approved['href']);
        $this->assertNull($approved['read_at']);
        $this->assertSame(2, $deskFeed['unread_count']);
    }

    public function test_task_assignment_notifies_only_the_assignee(): void
    {
        if (! Schema::hasColumn('room_status_blocks', 'assigned_to')) {
            Schema::table('room_status_blocks', function (Blueprint $table) {
                $table->unsignedBigInteger('assigned_to')->nullable();
            });
        }

        $assigner = $this->createUserWithPermission('housekeeping-dirty-rooms');
        $this->ensurePermission('housekeeping-assignable');
        $this->ensurePermission('housekeeping-checkout-inspection');
        $this->ensurePermission('housekeeping-checkout-inspection-assign');
        $this->ensurePermission('housekeeping-daily-room-cleaning');
        $assigner->givePermissionTo([
            'housekeeping-assignable',
            'housekeeping-checkout-inspection',
            'housekeeping-checkout-inspection-assign',
            'housekeeping-daily-room-cleaning',
        ]);
        $this->resetPermissionCache();

        $assignee = $this->createUserWithPermission('housekeeping-dirty-rooms');
        $assignee->givePermissionTo([
            'housekeeping-checkout-inspection',
            'housekeeping-daily-room-cleaning',
        ]);
        $other = $this->createUserWithPermission('housekeeping-dirty-rooms');
        $this->resetPermissionCache();

        $deptId = DB::table('departments')->insertGetId([
            'name' => 'Housekeeping',
            'code' => 'hk-'.uniqid(),
            'is_active' => true,
            'is_housekeeping' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('department_user')->insert([
            'department_id' => $deptId,
            'user_id' => $assignee->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $room = $this->createRoom('201');
        $today = Carbon::today();
        $dirty = RoomStatusBlock::query()->create([
            'room_id' => $room->id,
            'status' => 'dirty',
            'is_active' => true,
            'start_date' => $today->toDateString(),
            'end_date' => $today->copy()->addDay()->toDateString(),
        ]);

        Sanctum::actingAs($assigner);
        $this->postJson("/api/housekeeping/blocks/{$dirty->id}/assign-staff", [
            'assigned_to' => $assignee->id,
        ])->assertOk();

        $this->assertDatabaseHas('portal_notifications', [
            'audience' => 'dirty_rooms',
            'kind' => 'dirty_room.assigned',
            'recipient_user_id' => $assignee->id,
            'title' => 'Room 201 assigned to you',
        ]);

        $inspection = RoomStatusBlock::query()->create([
            'room_id' => $room->id,
            'status' => 'pending_inspection',
            'is_active' => true,
            'start_date' => $today->toDateString(),
            'end_date' => $today->copy()->addDay()->toDateString(),
        ]);
        $this->postJson("/api/housekeeping/blocks/{$inspection->id}/assign-staff", [
            'assigned_to' => $assignee->id,
        ])->assertOk();

        $booking = Booking::query()->create([
            'room_id' => $room->id,
            'status' => 'checked_in',
            'first_name' => 'Anurag',
            'last_name' => 'Mohan',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'check_in_at' => $today->copy()->subDay(),
            'check_out_at' => $today->copy()->addDay(),
        ]);
        BookingSegment::query()->create([
            'booking_id' => $booking->id,
            'room_id' => $room->id,
            'status' => 'checked_in',
            'check_in' => $today->toDateString(),
            'check_out' => $today->copy()->addDay()->toDateString(),
            'check_in_at' => $today->copy()->subDay(),
            'check_out_at' => $today->copy()->addDay(),
        ]);
        $this->createActiveRelease($room, [
            'booking_id' => $booking->id,
            'window_start' => now()->subHour(),
            'window_end' => now()->addHours(3),
        ]);
        $this->postJson('/api/housekeeping/daily-cleaning/status', [
            'room_id' => $room->id,
            'service_date' => $today->toDateString(),
            'status' => 'pending_cleaning',
            'assigned_to' => $assignee->id,
        ])->assertOk();

        Sanctum::actingAs($assignee);
        $assigneeFeed = $this->getJson('/api/notifications')->assertOk()->json();
        $assigneeKinds = array_column($assigneeFeed['notifications'], 'kind');
        $this->assertContains('dirty_room.assigned', $assigneeKinds);
        $this->assertContains('checkout_inspection.assigned', $assigneeKinds);
        $this->assertContains('daily_cleaning.assigned', $assigneeKinds);
        $this->assertSame(3, $assigneeFeed['unread_count']);

        Sanctum::actingAs($other);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        Sanctum::actingAs($assigner);
        $assignerFeed = $this->getJson('/api/notifications')->assertOk()->json();
        $assignerKinds = array_column($assignerFeed['notifications'], 'kind');
        $this->assertNotContains('dirty_room.assigned', $assignerKinds);
        $this->assertNotContains('checkout_inspection.assigned', $assignerKinds);
        $this->assertNotContains('daily_cleaning.assigned', $assignerKinds);
        $this->assertSame(0, $assignerFeed['unread_count']);
    }

    private function migratePortalNotificationTables(): void
    {
        if (! Schema::hasTable('portal_notifications')) {
            Schema::create('portal_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('kind', 64);
                $table->string('audience', 32)->nullable();
                $table->unsignedBigInteger('recipient_user_id')->nullable();
                $table->string('title');
                $table->text('message');
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('portal_notifications') && ! Schema::hasColumn('portal_notifications', 'audience')) {
            Schema::table('portal_notifications', function (Blueprint $table) {
                $table->string('audience', 32)->nullable();
            });
        }

        if (Schema::hasTable('portal_notifications') && ! Schema::hasColumn('portal_notifications', 'recipient_user_id')) {
            Schema::table('portal_notifications', function (Blueprint $table) {
                $table->unsignedBigInteger('recipient_user_id')->nullable();
            });
        }

        if (! Schema::hasTable('portal_notification_reads')) {
            Schema::create('portal_notification_reads', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('portal_notification_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('read_at');
                $table->timestamp('cleared_at')->nullable();
                $table->timestamps();
                $table->unique(['portal_notification_id', 'user_id']);
            });
        }

        if (! Schema::hasColumn('portal_notification_reads', 'cleared_at')) {
            Schema::table('portal_notification_reads', function (Blueprint $table) {
                $table->timestamp('cleared_at')->nullable();
            });
        }
    }
}
