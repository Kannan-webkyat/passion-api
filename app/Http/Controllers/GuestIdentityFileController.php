<?php

namespace App\Http\Controllers;

use App\Services\GuestIdentityImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GuestIdentityFileController extends Controller
{
    public function show(Request $request, string $path)
    {
        abort_unless($request->hasValidRelativeSignature(), 403);

        $service = app(GuestIdentityImageService::class);
        abort_unless($service->isManagedPath($path), 404);

        $disk = Storage::disk($service->disk());
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, max-age=300',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }
}
