<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Large file threshold
    |--------------------------------------------------------------------------
    | Raw byte size above which compression is attempted before storage.
    */
    'large_threshold_bytes' => (int) env('GUEST_IDENTITY_LARGE_BYTES', 5 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Image compression
    |--------------------------------------------------------------------------
    */
    'max_dimension' => (int) env('GUEST_IDENTITY_MAX_DIMENSION', 2048),
    'jpeg_quality' => (int) env('GUEST_IDENTITY_JPEG_QUALITY', 88),

    /*
    |--------------------------------------------------------------------------
    | Allowed MIME types for direct file uploads (multipart)
    |--------------------------------------------------------------------------
    */
    'allowed_mime_types' => [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload size limit
    |--------------------------------------------------------------------------
    | Maximum decoded size of one identity document.
    */
    'max_upload_bytes' => (int) env('GUEST_IDENTITY_MAX_UPLOAD_BYTES', 8 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Storage disk / directory
    |--------------------------------------------------------------------------
    | Must be a private disk: files are only reachable through signed
    | /api/guest-identity-files URLs (see url_ttl_minutes).
    */
    'disk' => env('GUEST_IDENTITY_DISK', 'local'),
    'directory' => env('GUEST_IDENTITY_DIRECTORY', 'identities'),

    /*
    |--------------------------------------------------------------------------
    | Signed URL lifetime (minutes)
    |--------------------------------------------------------------------------
    */
    'url_ttl_minutes' => (int) env('GUEST_IDENTITY_URL_TTL_MINUTES', 720),

];
