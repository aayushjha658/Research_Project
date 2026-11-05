<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
methodOnly('POST');
$d = requestData();
$email = strtolower(trim((string)($d['email'] ?? '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.'], 422);
$pdo = db();
$s = $pdo->prepare('SELECT id FROM users WHERE email=:email LIMIT 1');
$s->execute([':email' => $email]);
$u = $s->fetch();
$generic = 'If an account exists for this email, reset instructions have been generated.';
if (!$u) jsonResponse(['success' => true, 'message' => $generic]);
$raw = bin2hex(random_bytes(32));
$hash = hash('sha256', $raw);
$exp = date('Y-m-d H:i:s', time() + 3600);
$pdo->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE user_id=:id AND used_at IS NULL')->execute([':id' => $u['id']]);
$s = $pdo->prepare('INSERT INTO password_reset_tokens(user_id,token_hash,expires_at) VALUES(:uid,:hash,:exp)');
$s->execute([':uid' => $u['id'], ':hash' => $hash, ':exp' => $exp]);
$pdo->prepare('UPDATE users SET reset_token_hash=:h,reset_token_expires_at=:e WHERE id=:id')->execute([':h' => $hash, ':e' => $exp, ':id' => $u['id']]);
audit($pdo, (int)$u['id'], 'password_reset_requested');
$response = ['success' => true, 'message' => $generic];
if ((getenv('APP_ENV') ?: 'development') !== 'production') $response['reset_token'] = $raw; // development only
jsonResponse($response);
