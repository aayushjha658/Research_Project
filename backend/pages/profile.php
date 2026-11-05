<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';
$userId = requireAuth();
methodOnly('GET', 'POST');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $s = $pdo->prepare('SELECT id,full_name,email,bio,location,profile_image,role,status FROM users WHERE id=:id LIMIT 1');
    $s->execute([':id' => $userId]);
    $u = $s->fetch();
    if (!$u) jsonResponse(['success' => false, 'message' => 'User profile not found.'], 404);
    jsonResponse(['success' => true, 'csrf_token' => $_SESSION['csrf_token'], 'user' => [
        'id' => (int)$u['id'],
        'name' => $u['full_name'],
        'email' => $u['email'],
        'bio' => $u['bio'],
        'location' => $u['location'],
        'profile_image' => $u['profile_image'],
        'profile_image_url' => publicFileUrl($u['profile_image']),
        'role' => $u['role'],
        'status' => $u['status']
    ]]);
}
requireCsrf();
$name = safeText($_POST['full_name'] ?? '', 100);
$bio = safeText($_POST['bio'] ?? '', 2000);
$location = safeText($_POST['location'] ?? '', 150);
if (mb_strlen($name) < 2) jsonResponse(['success' => false, 'message' => 'Please enter a valid name.'], 422);
$s = $pdo->prepare('SELECT profile_image FROM users WHERE id=:id');
$s->execute([':id' => $userId]);
$u = $s->fetch();
if (!$u) jsonResponse(['success' => false, 'message' => 'User not found.'], 404);
$profile = $u['profile_image'];
$newFile = null;
if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['profile_image'];
    if ($f['error'] !== UPLOAD_ERR_OK) jsonResponse(['success' => false, 'message' => 'Unable to upload profile image.'], 422);
    if ((int)$f['size'] > 2 * 1024 * 1024) jsonResponse(['success' => false, 'message' => 'Profile image must be 2 MB or less.'], 422);
    if (!is_uploaded_file($f['tmp_name'])) jsonResponse(['success' => false, 'message' => 'Invalid image upload.'], 422);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) jsonResponse(['success' => false, 'message' => 'Only JPG, PNG and WebP images are allowed.'], 422);
    $dir = dirname(__DIR__, 2) . '/uploads/profile_images';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) jsonResponse(['success' => false, 'message' => 'Unable to create image folder.'], 500);
    $fn = 'user_' . $userId . '_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    $dest = $dir . '/' . $fn;
    if (!move_uploaded_file($f['tmp_name'], $dest)) jsonResponse(['success' => false, 'message' => 'Unable to save profile image.'], 500);
    $newFile = $dest;
    $profile = 'uploads/profile_images/' . $fn;
}
$s = $pdo->prepare('UPDATE users SET full_name=:n,bio=:b,location=:l,profile_image=:p WHERE id=:id');
$s->execute([':n' => $name, ':b' => $bio ?: null, ':l' => $location ?: null, ':p' => $profile, ':id' => $userId]);
$_SESSION['user_name'] = $name;
if ($newFile && $u['profile_image']) {
    $old = dirname(__DIR__, 2) . '/' . ltrim($u['profile_image'], '/');
    if (is_file($old) && realpath($old) !== realpath($newFile)) @unlink($old);
}
jsonResponse(['success' => true, 'message' => 'Profile updated successfully.', 'user' => ['id' => $userId, 'name' => $name, 'bio' => $bio, 'location' => $location, 'profile_image' => $profile, 'profile_image_url' => publicFileUrl($profile)]]);
