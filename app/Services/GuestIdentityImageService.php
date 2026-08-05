<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GuestIdentityImageService
{
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
            'url' => $trimmed !== '' ? Storage::disk($this->disk())->url($trimmed) : null,
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
        $mime = (string) $file->getMimeType();
        $allowed = config('guest_identity.allowed_mime_types', []);

        if ($allowed !== [] && ! in_array($mime, $allowed, true)) {
            throw new \InvalidArgumentException('Unsupported identity document type.');
        }

        if (str_starts_with($mime, 'image/')) {
            $binary = (string) file_get_contents($file->getRealPath());

            return $this->storeImageBinary($binary, $index, $originalBytes, $this->extensionForMime($mime));
        }

        // PDF and other allowed non-image types: store as-is (no server-side PDF compression).
        $ext = $file->getClientOriginalExtension() ?: 'bin';
        $fileName = $this->buildFileName($index, $ext, false);
        $path = $this->directory().'/'.$fileName;
        Storage::disk($this->disk())->putFileAs($this->directory(), $file, $fileName);

        return [
            'path' => $path,
            'url' => Storage::disk($this->disk())->url($path),
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
        if (! str_starts_with($dataUrl, 'data:image')) {
            throw new \InvalidArgumentException('Identity payload must be an image data URL.');
        }

        if (! preg_match('#^data:image/(\w+);base64,#i', $dataUrl, $matches)) {
            throw new \InvalidArgumentException('Invalid image data URL.');
        }

        $format = strtolower($matches[1] === 'jpeg' ? 'jpg' : $matches[1]);
        $binary = base64_decode((string) preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl), true);
        if ($binary === false) {
            throw new \InvalidArgumentException('Could not decode identity image.');
        }

        return $this->storeImageBinary($binary, $index, strlen($binary), $format);
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
    private function storeImageBinary(string $binary, int $index, int $originalBytes, string $sourceFormat): array
    {
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

        $suffix = $compressed ? '_compressed' : '';
        $ext = $compressed ? 'jpg' : ($sourceFormat === 'jpeg' ? 'jpg' : $sourceFormat);
        $fileName = $this->buildFileName($index, $ext, $compressed);
        $path = $this->directory().'/'.$fileName;

        Storage::disk($this->disk())->put($path, $storedBinary);

        return [
            'path' => $path,
            'url' => Storage::disk($this->disk())->url($path),
            'compressed' => $compressed,
            'original_bytes' => $originalBytes,
            'stored_bytes' => strlen($storedBinary),
        ];
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

        return 'guest_id_'.time().'_'.$index.$suffix.'.'.ltrim($extension, '.');
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }

    private function disk(): string
    {
        return (string) config('guest_identity.disk', 'public');
    }

    private function directory(): string
    {
        return trim((string) config('guest_identity.directory', 'identities'), '/');
    }
}
