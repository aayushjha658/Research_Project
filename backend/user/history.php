<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
methodOnly('GET', 'POST');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
    $s = $pdo->prepare("SELECT id,action_type,reference_id,title,metadata,created_at FROM research_history WHERE user_id=:id ORDER BY created_at DESC LIMIT {$limit}");
    $s->execute([':id' => $userId]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) {
        $r['metadata'] = $r['metadata'] ? json_decode($r['metadata'], true) : null;
    }
    jsonResponse(['success' => true, 'history' => $rows]);
}
requireCsrf();
$d = requestData();
$action = safeText($d['action_type'] ?? '', 60);
if ($action === '') jsonResponse(['success' => false, 'message' => 'action_type is required.'], 422);
$meta = $d['metadata'] ?? null;
$s = $pdo->prepare('INSERT INTO research_history(user_id,action_type,reference_id,title,metadata) VALUES(:uid,:a,:rid,:t,:m)');
$s->execute([':uid' => $userId, ':a' => $action, ':rid' => isset($d['reference_id']) ? (int)$d['reference_id'] : null, ':t' => safeText($d['title'] ?? '', 500) ?: null, ':m' => $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null]);
jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()], 201);
