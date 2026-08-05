<?php

namespace Tests\Unit\Services;

use App\Services\GuestIdentityImageService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuestIdentityImageServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'guest_identity.disk' => 'public',
            'guest_identity.directory' => 'identities',
            'guest_identity.large_threshold_bytes' => 1024,
            'guest_identity.max_dimension' => 800,
            'guest_identity.jpeg_quality' => 85,
        ]);
    }

    public function test_small_image_stored_without_compression_suffix(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $binary = $this->makePngBinary(120, 80);
        $dataUrl = 'data:image/png;base64,'.base64_encode($binary);

        $service = new GuestIdentityImageService;
        $result = $service->storeDataUrl($dataUrl, 0);

        $this->assertFalse($result['compressed']);
        $this->assertNotNull($result['path']);
        $this->assertStringNotContainsString('_compressed', (string) $result['path']);
        Storage::disk('public')->assertExists((string) $result['path']);
    }

    public function test_large_image_is_compressed_and_smaller(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension required.');
        }

        $binary = $this->makePngBinary(2400, 1800);
        $this->assertGreaterThan(1024, strlen($binary));

        $dataUrl = 'data:image/png;base64,'.base64_encode($binary);

        $service = new GuestIdentityImageService;
        $result = $service->storeDataUrl($dataUrl, 1);

        $this->assertTrue($result['compressed']);
        $this->assertStringContainsString('_compressed', (string) $result['path']);
        $this->assertLessThan($result['original_bytes'], $result['stored_bytes']);
        Storage::disk('public')->assertExists((string) $result['path']);
    }

    public function test_existing_path_passthrough(): void
    {
        $service = new GuestIdentityImageService;
        $result = $service->storeExistingPath('identities/existing.jpg');

        $this->assertSame('identities/existing.jpg', $result['path']);
        $this->assertFalse($result['compressed']);
    }

    private function makePngBinary(int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);
        $color = imagecolorallocate($img, 200, 40, 40);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $color);

        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return $binary;
    }
}
