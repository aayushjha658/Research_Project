<?php

declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $pdo = getDatabaseConnection();
    $pdo->query('SELECT 1');
    echo json_encode(['success' => true, 'status' => 'ok', 'database' => 'connected', 'time' => date(DATE_ATOM)]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success' => false, 'status' => 'error']);
}
