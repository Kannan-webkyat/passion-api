<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class GuestIdentityImageService
{
    private const IMAGE_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * @return array{
     *     path: string|null,
     *     url: string|null,
     *     compressed: bool,
     *     original_bytes: int,
     *     stored_bytes: int,
     * }
     */
    public function storeExistingPath(string $path): array
    {
        $trimmed = trim($path);

        return [
            'path' => $trimmed !== '' ? $trimmed : null,
            'url' => $trimmed !== '' ? $this->signedUrl($trimmed) : null,
            'compressed' => false,
            'original_bytes' => 0,
            'stored_bytes' => 0,
        ];
    }

    /**
     * @return array{
     *     path: string|null,
     *     url: string|null,
     *     compressed: bool,
     *     original_bytes: int,
     *     stored_bytes: int,
     * }
     */
    public function storeUploadedFile(UploadedFile $file, int $index): array
    {
        $originalBytes = (int) $file->getSize();
        $this->assertWithinSizeLimit($originalBytes);

        $mime = (string) $file->getMimeType();
        $allowed = config('guest_identity.allowed_mime_types', []);

        if ($allowed !== [] && ! in_array($mime, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported identity document type.');
        }

        $binary = (string) file_get_contents($file->getRealPath());

        if (str_starts_with($mime, 'image/')) {
            return $this->storeImageBinary($binary, $index, $originalBytes);
        }

        if ($mime !== 'application/pdf' || ! str_starts_with($binary, '%PDF-')) {
            throw new \InvalidArgumentException('Unsupported identity document type.');
        }

        // PDF: store as-is (no server-side PDF compression).
        $path = $this->directory().'/'.$this->buildFileName($index, 'pdf', false);
        Storage::disk($this->disk())->put($path, $binary);

        return [
            'path' => $path,
            'url' => $this->signedUrl($path),
            'compressed' => false,
            'original_bytes' => $originalBytes,
            'stored_bytes' => $originalBytes,
        ];
    }

    /**
     * @return array{
     *     path: string|null,
     *     url: string|null,
     *     compressed: bool,
     *     original_bytes: int,
     *     stored_bytes: int,
     * }
     */
    public function storeDataUrl(string $dataUrl, int $index): array
    {
        if (! preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $dataUrl, $matches)) {
            throw new \InvalidArgumentException('Identity image must be a JPEG, PNG or WebP data URL.');
        }

        $binary = base64_decode(substr($dataUrl, strlen($matches[0])), true);
        if ($binary === false || $binary === '') {
            throw new \InvalidArgumentException('Could not decode identity image.');
        }

        $this->assertWithinSizeLimit(strlen($binary));

        return $this->storeImageBinary($binary, $index, strlen($binary));
    }

    /**
     * True when the path points inside the identity directory (no traversal, no URLs).
     */
    public function isManagedPath(string $path): bool
    {
        $path = trim($path);
        $prefix = $this->directory().'/';

        return str_starts_with($path, $prefix)
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && preg_match('#^[A-Za-z0-9_\-/]+\.[A-Za-z0-9]+$#', $path) === 1;
    }

    public function signedUrl(?string $path): ?string
    {
        if ($path === null || trim($path) === '' || ! $this->isManagedPath($path)) {
            return null;
        }

        $ttl = max(1, (int) config('guest_identity.url_ttl_minutes', 720));

        return url(URL::temporarySignedRoute(
            'guest-identity.file',
            now()->addMinutes($ttl),
            ['path' => trim($path)],
            false,
        ));
    }

    /**
     * Signed display URLs aligned by index with the stored paths (null where empty).
     *
     * @param  array<int, mixed>|null  $paths
     * @return array<int, string|null>
     */
    public function signedUrls(?array $paths): array
    {
        if (! is_array($paths)) {
            return [];
        }

        return array_map(
            fn ($p) => is_string($p) ? $this->signedUrl($p) : null,
            array_values($paths),
        );
    }

    public function disk(): string
    {
        return (string) config('guest_identity.disk', 'local');
    }

    /**
     * @return array{
     *     path: string|null,
     *     url: string|null,
     *     compressed: bool,
     *     original_bytes: int,
     *     stored_bytes: int,
     * }
     */
    private function storeImageBinary(string $binary, int $index, int $originalBytes): array
    {
        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (! isset(self::IMAGE_EXTENSIONS[$mime])) {
            throw new \InvalidArgumentException('Identity image must be a valid JPEG, PNG or WebP file.');
        }

        $threshold = (int) config('guest_identity.large_threshold_bytes', 5 * 1024 * 1024);
        $storedBinary = $binary;
        $compressed = false;

        if ($originalBytes > $threshold || $originalBytes > 1024 * 1024) {
            $candidate = $this->compressImageBinary($binary);
            if ($candidate !== null && strlen($candidate) > 0 && strlen($candidate) < strlen($binary)) {
                $storedBinary = $candidate;
                $compressed = true;
            }
        }

        $ext = $compressed ? 'jpg' : self::IMAGE_EXTENSIONS[$mime];
        $path = $this->directory().'/'.$this->buildFileName($index, $ext, $compressed);

        Storage::disk($this->disk())->put($path, $storedBinary);

        return [
            'path' => $path,
            'url' => $this->signedUrl($path),
            'compressed' => $compressed,
            'original_bytes' => $originalBytes,
            'stored_bytes' => strlen($storedBinary),
        ];
    }

    private function assertWithinSizeLimit(int $bytes): void
    {
        $max = (int) config('guest_identity.max_upload_bytes', 8 * 1024 * 1024);
        if ($max > 0 && $bytes > $max) {
            throw new \InvalidArgumentException(
                'Identity document is too large (max '.round($max / 1024 / 1024, 1).' MB).'
            );
        }
    }

    private function compressImageBinary(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            Log::warning('GuestIdentityImageService: GD extension unavailable — skipping compression.');

            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            Log::warning('GuestIdentityImageService: could not parse image for compression.');

            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxDimension = max(1, (int) config('guest_identity.max_dimension', 2048));

        if ($width > $maxDimension || $height > $maxDimension) {
            $scale = min($maxDimension / $width, $maxDimension / $height);
            $targetW = max(1, (int) round($width * $scale));
            $targetH = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($targetW, $targetH);
            if ($resized === false) {
                imagedestroy($image);

                return null;
            }
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        ob_start();
        $quality = max(60, min(100, (int) config('guest_identity.jpeg_quality', 88)));
        $ok = imagejpeg($image, null, $quality);
        $output = ob_get_clean();
        imagedestroy($image);

        if (! $ok || ! is_string($output) || $output === '') {
            return null;
        }

        return $output;
    }

    private function buildFileName(int $index, string $extension, bool $compressed): string
    {
        $suffix = $compressed ? '_compressed' : '';

        return 'guest_id_'.Str::random(40).'_'.$index.$suffix.'.'.ltrim($extension, '.');
    }

    private function directory(): string
    {
        return trim((string) config('guest_identity.directory', 'identities'), '/');
    }
}
