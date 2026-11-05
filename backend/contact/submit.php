<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
methodOnly('POST');
$d = requestData();
$name = safeText($d['fullName'] ?? $d['full_name'] ?? '', 100);
$email = strtolower(trim((string)($d['email'] ?? '')));
$subject = safeText($d['subject'] ?? '', 80);
$message = safeText($d['message'] ?? '', 1000);
$allowed = ['research-support', 'technical-support', 'feedback', 'account', 'other'];
if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($subject, $allowed, true) || $message === '') jsonResponse(['success' => false, 'message' => 'Please complete all contact fields correctly.'], 422);
$pdo = db();
$uid = !empty($_SESSION['authenticated']) ? (int)$_SESSION['user_id'] : null;
$s = $pdo->prepare('INSERT INTO contact_messages(user_id,full_name,email,subject,message) VALUES(:uid,:n,:e,:s,:m)');
$s->execute([':uid' => $uid, ':n' => $name, ':e' => $email, ':s' => $subject, ':m' => $message]);
if ($uid) audit($pdo, $uid, 'contact_message_submitted');
jsonResponse(['success' => true, 'message' => 'Your message has been sent successfully.']);
