<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

$key = getenv('GEMINI_API_KEY');

header('Content-Type: application/json');

echo json_encode([
    'key_loaded' => !empty($key),
    'key_length' => is_string($key) ? strlen($key) : 0,
    'key_prefix' => is_string($key) && strlen($key) >= 4
        ? substr($key, 0, 4) . '***'
        : null,
]);