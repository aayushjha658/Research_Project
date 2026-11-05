<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}

/* CSRF */
$csrfToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($csrfToken) ||
    empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    jsonResponse([
        'success' => false,
        'message' => 'Invalid security token. Please refresh the page.'
    ], 403);
}

/* Input */
$fullName = trim((string)($_POST['full_name'] ?? ''));
$email = trim(strtolower((string)($_POST['email'] ?? '')));
$password = (string)($_POST['password'] ?? '');
$confirmPassword = (string)($_POST['confirm_password'] ?? '');
$termsAccepted = isset($_POST['terms']) && $_POST['terms'] === '1';

/* Validation */
if ($fullName === '' || mb_strlen($fullName) < 2 || mb_strlen($fullName) > 100) {
    jsonResponse([
        'success' => false,
        'message' => 'Please enter a valid name.'
    ], 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
    jsonResponse([
        'success' => false,
        'message' => 'Please enter a valid email address.'
    ], 422);
}

if (strlen($password) < 8) {
    jsonResponse([
        'success' => false,
        'message' => 'Password must contain at least 8 characters.'
    ], 422);
}

if (
    !preg_match('/[A-Z]/', $password) ||
    !preg_match('/[a-z]/', $password) ||
    !preg_match('/[0-9]/', $password)
) {
    jsonResponse([
        'success' => false,
        'message' => 'Password must contain uppercase, lowercase and a number.'
    ], 422);
}

if (!hash_equals($password, $confirmPassword)) {
    jsonResponse([
        'success' => false,
        'message' => 'Passwords do not match.'
    ], 422);
}

if (!$termsAccepted) {
    jsonResponse([
        'success' => false,
        'message' => 'Please accept the Terms and Conditions.'
    ], 422);
}

/*
 * Profile image upload.
 * The file is optional, so the old signup flow still works if no photo is selected.
 */
$profileImagePath = null;
$uploadedFilePath = null;

if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {

    $image = $_FILES['profile_image'];

    if ($image['error'] !== UPLOAD_ERR_OK) {
        jsonResponse([
            'success' => false,
            'message' => 'Unable to upload the profile image.'
        ], 422);
    }

    if ((int)$image['size'] > 2 * 1024 * 1024) {
        jsonResponse([
            'success' => false,
            'message' => 'Image size must be 2 MB or less.'
        ], 422);
    }

    $tmpFile = (string)$image['tmp_name'];

    if (!is_uploaded_file($tmpFile)) {
        jsonResponse([
            'success' => false,
            'message' => 'Invalid image upload.'
        ], 422);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpFile);

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!isset($allowedTypes[$mime])) {
        jsonResponse([
            'success' => false,
            'message' => 'Only JPG, PNG or WebP images are allowed.'
        ], 422);
    }

    $uploadDirectory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_images';

    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        jsonResponse([
            'success' => false,
            'message' => 'Unable to create the profile image folder.'
        ], 500);
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mime];
    $destination = $uploadDirectory . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($tmpFile, $destination)) {
        jsonResponse([
            'success' => false,
            'message' => 'Unable to save the profile image.'
        ], 500);
    }

    $uploadedFilePath = $destination;
    $profileImagePath = 'uploads/profile_images/' . $fileName;
}

try {

    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    /* Duplicate email */
    $checkStmt = $pdo->prepare(
        'SELECT id FROM users WHERE email = :email LIMIT 1'
    );

    $checkStmt->execute([':email' => $email]);

    if ($checkStmt->fetch()) {
        $pdo->rollBack();

        if ($uploadedFilePath && is_file($uploadedFilePath)) {
            @unlink($uploadedFilePath);
        }

        jsonResponse([
            'success' => false,
            'message' => 'Unable to create the account with these details.'
        ], 409);
    }

    /* Password */
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    /* User */
    $insertStmt = $pdo->prepare(
        'INSERT INTO users
        (
            full_name,
            email,
            password_hash,
            profile_image,
            role,
            status,
            password_changed_at
        )
        VALUES
        (
            :full_name,
            :email,
            :password_hash,
            :profile_image,
            :role,
            :status,
            NOW()
        )'
    );

    $insertStmt->execute([
        ':full_name' => $fullName,
        ':email' => $email,
        ':password_hash' => $passwordHash,
        ':profile_image' => $profileImagePath,
        ':role' => 'user',
        ':status' => 'active'
    ]);

    $userId = (int)$pdo->lastInsertId();

    /* Security audit event */
    $eventStmt = $pdo->prepare(
        'INSERT INTO security_events
        (
            user_id,
            event_type,
            ip_address,
            user_agent
        )
        VALUES
        (
            :user_id,
            :event_type,
            :ip_address,
            :user_agent
        )'
    );

    $eventStmt->execute([
        ':user_id' => $userId,
        ':event_type' => 'account_created',
        ':ip_address' => getClientIp(),
        ':user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512)
    ]);

    $pdo->commit();

    /* Session */
    session_regenerate_id(true);

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $fullName;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_role'] = 'user';
    $_SESSION['authenticated'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    jsonResponse([
        'success' => true,
        'message' => 'Account created successfully.',
        'redirect' => '../../pages/dashboard.html'
    ], 201);

} catch (PDOException $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($uploadedFilePath && is_file($uploadedFilePath)) {
        @unlink($uploadedFilePath);
    }

    error_log('Signup database error: ' . $e->getMessage());

    $errorInfo = $e->errorInfo ?? [];

    if (isset($errorInfo[1]) && (int)$errorInfo[1] === 1062) {
        jsonResponse([
            'success' => false,
            'message' => 'Unable to create the account with these details.'
        ], 409);
    }

    jsonResponse([
        'success' => false,
        'message' => 'Unable to create your account right now.'
    ], 500);

} catch (Throwable $e) {

    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($uploadedFilePath && is_file($uploadedFilePath)) {
        @unlink($uploadedFilePath);
    }

    error_log('Signup error: ' . $e->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'Something went wrong. Please try again later.'
    ], 500);
}