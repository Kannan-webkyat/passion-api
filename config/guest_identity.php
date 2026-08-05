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
        'image/gif',
        'application/pdf',
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage disk / directory
    |--------------------------------------------------------------------------
    */
    'disk' => env('GUEST_IDENTITY_DISK', 'public'),
    'directory' => env('GUEST_IDENTITY_DIRECTORY', 'identities'),

];
