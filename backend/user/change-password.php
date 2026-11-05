<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
methodOnly('POST');
requireCsrf();
$d = requestData();
$current = (string)($d['current_password'] ?? '');
$new = (string)($d['new_password'] ?? '');
$confirm = (string)($d['confirm_password'] ?? '');
if ($current === '' || $new === '' || $confirm === '') jsonResponse(['success' => false, 'message' => 'All password fields are required.'], 422);
if (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new)) jsonResponse(['success' => false, 'message' => 'New password must contain at least 8 characters, including uppercase, lowercase and a number.'], 422);
if ($new !== $confirm) jsonResponse(['success' => false, 'message' => 'Passwords do not match.'], 422);
$pdo = db();
$s = $pdo->prepare('SELECT password_hash FROM users WHERE id=:id');
$s->execute([':id' => $userId]);
$u = $s->fetch();
if (!$u || !password_verify($current, $u['password_hash'])) jsonResponse(['success' => false, 'message' => 'Current password is incorrect.'], 422);
$hash = password_hash($new, PASSWORD_DEFAULT);
$s = $pdo->prepare('UPDATE users SET password_hash=:h,password_changed_at=NOW(),reset_token_hash=NULL,reset_token_expires_at=NULL WHERE id=:id');
$s->execute([':h' => $hash, ':id' => $userId]);
audit($pdo, $userId, 'password_changed');
jsonResponse(['success' => true, 'message' => 'Password changed successfully.']);
