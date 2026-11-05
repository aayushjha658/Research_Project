<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') methodOnly('GET');
$s = $pdo->prepare('SELECT id,full_name,email,profile_image,role,status,last_login_at FROM users WHERE id=:id LIMIT 1');
$s->execute([':id' => $userId]);
$u = $s->fetch();
if (!$u || $u['status'] !== 'active') {
    $_SESSION = [];
    session_destroy();
    jsonResponse(['success' => false, 'authenticated' => false, 'message' => 'Your account is not active.'], 403);
}
$q = $pdo->query('SELECT COUNT(*) FROM research_papers');
$papers = (int)$q->fetchColumn();
$q = $pdo->prepare('SELECT COUNT(*) FROM bookmarks WHERE user_id=:id');
$q->execute([':id' => $userId]);
$bookmarks = (int)$q->fetchColumn();
$q = $pdo->prepare('SELECT COUNT(*) FROM research_ideas WHERE user_id=:id');
$q->execute([':id' => $userId]);
$ideas = (int)$q->fetchColumn();
$q = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=:id AND is_read=0');
$q->execute([':id' => $userId]);
$unread = (int)$q->fetchColumn();
$recent = $pdo->query('SELECT id,title,authors,journal,publication_year,doi,url,created_at FROM research_papers ORDER BY created_at DESC LIMIT 5')->fetchAll();
jsonResponse(['success' => true, 'authenticated' => true, 'csrf_token' => $_SESSION['csrf_token'], 'user' => ['id' => (int)$u['id'], 'name' => $u['full_name'], 'email' => $u['email'], 'profile_image' => $u['profile_image'], 'profile_image_url' => publicFileUrl($u['profile_image']), 'role' => $u['role']], 'stats' => ['papers' => $papers, 'bookmarks' => $bookmarks, 'ideas' => $ideas, 'unread_notifications' => $unread], 'recent_papers' => $recent]);
