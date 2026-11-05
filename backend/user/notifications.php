<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
methodOnly('GET', 'POST');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
    $s = $pdo->prepare("SELECT id,title,message,type,is_read,link,created_at FROM notifications WHERE user_id=:id ORDER BY created_at DESC LIMIT {$limit}");
    $s->execute([':id' => $userId]);
    $rows = $s->fetchAll();
    foreach ($rows as &$r) $r['is_read'] = (bool)$r['is_read'];
    $u = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=:id AND is_read=0');
    $u->execute([':id' => $userId]);
    jsonResponse(['success' => true, 'notifications' => $rows, 'unread_count' => (int)$u->fetchColumn()]);
}
requireCsrf();
$d = requestData();
$id = (int)($d['id'] ?? 0);
if ($id > 0) {
    $s = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE id=:id AND user_id=:uid');
    $s->execute([':id' => $id, ':uid' => $userId]);
} else {
    $s = $pdo->prepare('UPDATE notifications SET is_read=1 WHERE user_id=:uid');
    $s->execute([':uid' => $userId]);
}
jsonResponse(['success' => true, 'message' => 'Notification status updated.']);
