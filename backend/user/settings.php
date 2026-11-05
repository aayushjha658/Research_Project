<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
methodOnly('GET', 'POST');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = $pdo->prepare('SELECT theme,language,email_notifications,research_notifications,security_notifications FROM user_settings WHERE user_id=:id');
    $s->execute([':id' => $userId]);
    $settings = $s->fetch() ?: ['theme' => 'system', 'language' => 'en', 'email_notifications' => 1, 'research_notifications' => 1, 'security_notifications' => 1];
    jsonResponse(['success' => true, 'csrf_token' => $_SESSION['csrf_token'], 'settings' => [
        'theme' => $settings['theme'],
        'language' => $settings['language'],
        'email_notifications' => (bool)$settings['email_notifications'],
        'research_notifications' => (bool)$settings['research_notifications'],
        'security_notifications' => (bool)$settings['security_notifications']
    ]]);
}
requireCsrf();
$d = requestData();
$theme = (string)($d['theme'] ?? 'system');
$language = safeText($d['language'] ?? 'en', 20);
if (!in_array($theme, ['light', 'dark', 'system'], true)) jsonResponse(['success' => false, 'message' => 'Invalid theme.'], 422);
$vals = [];
foreach (['email_notifications', 'research_notifications', 'security_notifications'] as $k) $vals[$k] = !empty($d[$k]) ? 1 : 0;
$s = $pdo->prepare('INSERT INTO user_settings(user_id,theme,language,email_notifications,research_notifications,security_notifications) VALUES(:id,:theme,:lang,:e,:r,:s) ON DUPLICATE KEY UPDATE theme=VALUES(theme),language=VALUES(language),email_notifications=VALUES(email_notifications),research_notifications=VALUES(research_notifications),security_notifications=VALUES(security_notifications)');
$s->execute([':id' => $userId, ':theme' => $theme, ':lang' => $language, ':e' => $vals['email_notifications'], ':r' => $vals['research_notifications'], ':s' => $vals['security_notifications']]);
jsonResponse(['success' => true, 'message' => 'Settings saved successfully.']);
