<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

startSecureSession();

function requireAuth(): int
{
    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true || empty($_SESSION['user_id'])) {
        jsonResponse(['success'=>false,'authenticated'=>false,'message'=>'Authentication required.'],401);
    }
    return (int)$_SESSION['user_id'];
}

function requireAdmin(): int
{
    $id = requireAuth();
    if (($_SESSION['user_role'] ?? 'user') !== 'admin') {
        jsonResponse(['success'=>false,'message'=>'Administrator access required.'],403);
    }
    return $id;
}

function requireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    $session = $_SESSION['csrf_token'] ?? '';
    if (!is_string($token) || !is_string($session) || $token === '' || !hash_equals($session,$token)) {
        jsonResponse(['success'=>false,'message'=>'Invalid security token. Please refresh the page.'],403);
    }
}

function requestData(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType,'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw)==='') return [];
        $data = json_decode($raw,true);
        if (!is_array($data)) jsonResponse(['success'=>false,'message'=>'Invalid JSON request.'],400);
        return $data;
    }
    return $_POST;
}

function methodOnly(string ...$methods): void
{
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $allowed = array_map('strtoupper',$methods);
    if (!in_array($method,$allowed,true)) {
        header('Allow: '.implode(', ',$allowed));
        jsonResponse(['success'=>false,'message'=>'Invalid request method.'],405);
    }
}

function db(): PDO { return getDatabaseConnection(); }

function audit(PDO $pdo, ?int $userId, string $event): void
{
    try {
        $s=$pdo->prepare('INSERT INTO security_events (user_id,event_type,ip_address,user_agent) VALUES (:uid,:event,:ip,:ua)');
        $s->execute([
            ':uid'=>$userId, ':event'=>$event, ':ip'=>getClientIp(),
            ':ua'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,512)
        ]);
    } catch (Throwable $e) { error_log('Audit log failed: '.$e->getMessage()); }
}

function publicFileUrl(?string $path): ?string
{
    if (!$path) return null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim(dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''))),'/');
    return $scheme.'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').$base.'/'.ltrim($path,'/');
}

function safeText(mixed $v, int $max=5000): string
{
    $s=trim((string)$v);
    return mb_substr($s,0,$max);
}
