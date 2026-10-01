<?php
return [
    // Exact scheme + hostname + optional port; no trailing slash.
    'allowed_origin' => getenv('UPLOAD_ALLOWED_ORIGIN') ?: '',
    // Absolute public service URL, including a subdirectory if used.
    'base_url' => getenv('UPLOAD_BASE_URL') ?: 'http://localhost:8080',
    // Keep outside the public web directory.
    'storage_dir' => getenv('UPLOAD_STORAGE_DIR') ?: __DIR__ . '/storage/images',
    'max_image_bytes' => 10 * 1024 * 1024,
    'max_batch_images' => 20,
];
