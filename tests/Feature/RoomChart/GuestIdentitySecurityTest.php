<?php

namespace Tests\Feature\RoomChart;

use App\Models\Booking;
use App\Models\Room;
use Illuminate\Support\Facades\Storage;

class GuestIdentitySecurityTest extends RoomChartTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        config(['guest_identity.disk' => 'local', 'guest_identity.directory' => 'identities']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(Room $room, array $overrides = []): array
    {
        return array_merge([
            'room_id' => $room->id,
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'phone' => '9876543210',
            'adults_count' => 2,
            'check_in' => $this->day(0),
            'check_out' => $this->day(2),
            'total_price' => 4480,
            'rate_plan_id' => $this->dayPlan->id,
        ], $overrides);
    }

    private function pngDataUrl(): string
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($binary);
    }

    public function test_upload_is_stored_privately_with_random_name_and_signed_url(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $response = $this->postJson('/api/bookings', $this->payload($room, [
            'guest_identities' => [$this->pngDataUrl()],
        ]))->assertCreated();

        $path = $response->json('guest_identities.0');
        $this->assertMatchesRegularExpression('#^identities/guest_id_[A-Za-z0-9]{40}_0\.png$#', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);

        $url = (string) $response->json('guest_identity_urls.0');
        $this->assertStringContainsString('/api/guest-identity-files/'.$path, $url);
        $this->assertStringContainsString('signature=', $url);

        $relative = (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->get($relative)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_file_route_rejects_missing_or_tampered_signature(): void
    {
        Storage::disk('local')->put('identities/guest_id_x_0.png', 'x');

        $this->get('/api/guest-identity-files/identities/guest_id_x_0.png')->assertForbidden();
        $this->get('/api/guest-identity-files/identities/guest_id_x_0.png?expires=9999999999&signature=bad')->assertForbidden();
    }

    public function test_rejects_non_image_payload_disguised_as_image(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $php = 'data:image/php;base64,'.base64_encode('<?php echo 1;');
        $this->postJson('/api/bookings', $this->payload($room, ['guest_identities' => [$php]]))
            ->assertStatus(422);

        $fakePng = 'data:image/png;base64,'.base64_encode('<html><script>alert(1)</script></html>');
        $this->postJson('/api/bookings', $this->payload($room, ['guest_identities' => [$fakePng]]))
            ->assertStatus(422);

        $this->assertSame(0, Booking::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('identities'));
    }

    public function test_create_rejects_reusing_a_stored_path(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');

        $this->postJson('/api/bookings', $this->payload($room, [
            'guest_identities' => ['identities/guest_id_other_0.jpg'],
        ]))->assertStatus(422);
    }

    public function test_update_keeps_own_paths_but_rejects_foreign_paths(): void
    {
        $this->actingWith(['reservation-edit']);
        $room = $this->makeRoom('101');
        $booking = $this->makeBooking($room, $this->day(0), $this->day(2), [
            'guest_identities' => ['identities/guest_id_mine_0.jpg'],
        ]);

        $this->patchJson("/api/bookings/{$booking->id}", [
            'guest_identities' => ['identities/guest_id_mine_0.jpg', null],
        ])->assertOk();

        $this->patchJson("/api/bookings/{$booking->id}", [
            'guest_identities' => ['identities/guest_id_someone_else_0.jpg'],
        ])->assertStatus(422);

        $this->assertSame(['identities/guest_id_mine_0.jpg', null], $booking->fresh()->guest_identities);
    }

    public function test_guest_search_escapes_wildcards_and_omits_identity_documents(): void
    {
        $this->actingWith(['reservation-create']);
        $room = $this->makeRoom('101');
        $this->makeBooking($room, $this->day(0), $this->day(2), [
            'phone' => '9876543210',
            'guest_identities' => ['identities/guest_id_mine_0.jpg'],
        ]);

        $this->getJson('/api/bookings/guest-search?phone='.urlencode('%%%%'))->assertStatus(422);
        $this->getJson('/api/bookings/guest-search?phone=3210')->assertStatus(422);

        $this->getJson('/api/bookings/guest-search?phone=9876543')
            ->assertOk()
            ->assertJsonPath('phone', '9876543210')
            ->assertJsonMissingPath('guest_identities')
            ->assertJsonMissingPath('guest_identity_types');
    }
}
