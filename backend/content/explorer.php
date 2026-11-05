<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
requireAuth();
methodOnly('GET');
$pdo = db();
$type = $_GET['type'] ?? 'datasets';
$allowed = ['datasets', 'models', 'conferences'];
if (!in_array($type, $allowed, true)) jsonResponse(['success' => false, 'message' => 'Invalid content type.'], 422);
$table = $type === 'datasets' ? 'datasets' : ($type === 'models' ? 'ai_models' : 'conferences');
$search = trim((string)($_GET['search'] ?? ''));
$domain = trim((string)($_GET['domain'] ?? ''));
$limit = max(1, min(50, (int)($_GET['limit'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;
$where = [];
$params = [];
if ($search !== '') {
    $where[] = $type === 'datasets' ? '(name LIKE :q OR description LIKE :q OR domain LIKE :q)' : ($type === 'models' ? '(name LIKE :q OR architecture LIKE :q OR domain LIKE :q)' : '(name LIKE :q OR organizer LIKE :q OR field LIKE :q)');
    $params[':q'] = '%' . $search . '%';
}
if ($domain !== '') {
    $col = $type === 'conferences' ? 'field' : 'domain';
    $where[] = "$col LIKE :domain";
    $params[':domain'] = '%' . $domain . '%';
}
$ws = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$s = $pdo->prepare("SELECT COUNT(*) FROM $table $ws");
foreach ($params as $k => $v) $s->bindValue($k, $v);
$s->execute();
$total = (int)$s->fetchColumn();
$order = $type === 'conferences' ? 'deadline IS NULL,deadline ASC,created_at DESC' : 'created_at DESC';
$s = $pdo->prepare("SELECT * FROM $table $ws ORDER BY $order LIMIT :limit OFFSET :offset");
foreach ($params as $k => $v) $s->bindValue($k, $v);
$s->bindValue(':limit', $limit, PDO::PARAM_INT);
$s->bindValue(':offset', $offset, PDO::PARAM_INT);
$s->execute();
jsonResponse(['success' => true, 'type' => $type, 'items' => $s->fetchAll(), 'pagination' => ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => $total ? (int)ceil($total / $limit) : 0]]);
