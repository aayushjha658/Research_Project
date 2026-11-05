<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
methodOnly('POST');
$d = requestData();
$token = preg_replace('/[^a-f0-9]/i', '', (string)($d['token'] ?? ''));
$new = (string)($d['password'] ?? '');
$confirm = (string)($d['confirm_password'] ?? '');
if (strlen($token) !== 64) jsonResponse(['success' => false, 'message' => 'Invalid or expired reset token.'], 422);
if (strlen($new) < 8 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new)) jsonResponse(['success' => false, 'message' => 'Password must contain uppercase, lowercase and a number.'], 422);
if ($new !== $confirm) jsonResponse(['success' => false, 'message' => 'Passwords do not match.'], 422);
$hash = hash('sha256', $token);
$pdo = db();
$s = $pdo->prepare('SELECT id,user_id FROM password_reset_tokens WHERE token_hash=:h AND used_at IS NULL AND expires_at>NOW() LIMIT 1');
$s->execute([':h' => $hash]);
$row = $s->fetch();
if (!$row) jsonResponse(['success' => false, 'message' => 'Invalid or expired reset token.'], 422);
$pdo->beginTransaction();
try {
    $ph = password_hash($new, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE users SET password_hash=:p,password_changed_at=NOW(),reset_token_hash=NULL,reset_token_expires_at=NULL,failed_login_attempts=0,locked_until=NULL WHERE id=:id')->execute([':p' => $ph, ':id' => $row['user_id']]);
    $pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=:id')->execute([':id' => $row['id']]);
    audit($pdo, (int)$row['user_id'], 'password_reset_completed');
    $pdo->commit();
    jsonResponse(['success' => true, 'message' => 'Password reset successfully.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log($e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Unable to reset password right now.'], 500);
}
