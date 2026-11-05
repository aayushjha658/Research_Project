<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

startSecureSession();

header(
    'Content-Type: application/json; charset=utf-8'
);


/*
|--------------------------------------------------------------------------
| Only POST requests
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    jsonResponse([
        'success' => false,
        'message' => 'Invalid request method.'
    ], 405);
}


/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
*/

$csrfToken =
    $_POST['csrf_token'] ?? '';

$sessionToken =
    $_SESSION['csrf_token'] ?? '';

if (
    !is_string($csrfToken) ||
    !is_string($sessionToken) ||
    $csrfToken === '' ||
    $sessionToken === '' ||
    !hash_equals(
        $sessionToken,
        $csrfToken
    )
) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Security validation failed. Please refresh the page.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| Get Input
|--------------------------------------------------------------------------
*/

$email =
    trim(
        strtolower(
            (string)($_POST['email'] ?? '')
        )
    );

$password =
    (string)($_POST['password'] ?? '');


/*
|--------------------------------------------------------------------------
| Basic Validation
|--------------------------------------------------------------------------
*/

if ($email === '' || $password === '') {

    jsonResponse([
        'success' => false,
        'message' =>
            'Email and password are required.'
    ], 422);
}


if (
    !filter_var(
        $email,
        FILTER_VALIDATE_EMAIL
    )
) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Please enter a valid email address.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

try {

    $pdo =
        getDatabaseConnection();

} catch (Throwable $e) {

    error_log(
        'Research AI LOGIN DATABASE ERROR: ' .
        $e->getMessage()
    );

    jsonResponse([
        'success' => false,
        'message' =>
            'Unable to process your request right now.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Find User
|--------------------------------------------------------------------------
*/

try {

    $stmt =
        $pdo->prepare(
            'SELECT
                id,
                full_name,
                email,
                password_hash,
                role,
                status,
                failed_login_attempts,
                locked_until
             FROM users
             WHERE email = :email
             LIMIT 1'
        );


    $stmt->execute([
        ':email' => $email
    ]);


    $user =
        $stmt->fetch();


} catch (PDOException $e) {

    error_log(
        'Research AI LOGIN USER QUERY ERROR: ' .
        $e->getMessage()
    );

    jsonResponse([
        'success' => false,
        'message' =>
            'Unable to process your request right now.'
    ], 500);
}


/*
|--------------------------------------------------------------------------
| Generic Invalid Login Response
|--------------------------------------------------------------------------
|
| Do not reveal whether an email exists.
|
*/

$invalidLoginMessage =
    'Invalid email or password.';


if (!$user) {

    jsonResponse([
        'success' => false,
        'message' =>
            $invalidLoginMessage
    ], 401);
}


/*
|--------------------------------------------------------------------------
| Account Status
|--------------------------------------------------------------------------
*/

$status =
    strtolower(
        (string)($user['status'] ?? 'active')
    );


if ($status !== 'active') {

    jsonResponse([
        'success' => false,
        'message' =>
            'This account is currently unavailable.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| Check Account Lock
|--------------------------------------------------------------------------
*/

$lockedUntil =
    $user['locked_until'] ?? null;


if (
    !empty($lockedUntil) &&
    strtotime((string)$lockedUntil) > time()
) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Too many failed login attempts. Please try again later.'
    ], 429);
}


/*
|--------------------------------------------------------------------------
| Verify Password
|--------------------------------------------------------------------------
*/

$passwordHash =
    (string)$user['password_hash'];


if (
    $passwordHash === '' ||
    !password_verify(
        $password,
        $passwordHash
    )
) {

    /*
     * Increase failed login attempts.
     */

    try {

        $failedAttempts =
            (int)(
                $user['failed_login_attempts'] ?? 0
            );

        $failedAttempts++;


        /*
         * Lock account temporarily after
         * repeated failed attempts.
         */

        $maxAttempts = 5;

        if (
            $failedAttempts >=
            $maxAttempts
        ) {

            $lockMinutes = 15;

            $lockUntil =
                date(
                    'Y-m-d H:i:s',
                    time() +
                    ($lockMinutes * 60)
                );

            $update =
                $pdo->prepare(
                    'UPDATE users
                     SET
                        failed_login_attempts =
                            :failed_attempts,
                        locked_until =
                            :locked_until
                     WHERE id = :id'
                );

            $update->execute([
                ':failed_attempts' =>
                    $failedAttempts,

                ':locked_until' =>
                    $lockUntil,

                ':id' =>
                    $user['id']
            ]);

        } else {

            $update =
                $pdo->prepare(
                    'UPDATE users
                     SET
                        failed_login_attempts =
                            :failed_attempts
                     WHERE id = :id'
                );

            $update->execute([
                ':failed_attempts' =>
                    $failedAttempts,

                ':id' =>
                    $user['id']
            ]);
        }


    } catch (Throwable $e) {

        error_log(
            'Research AI FAILED LOGIN UPDATE ERROR: ' .
            $e->getMessage()
        );
    }


    jsonResponse([
        'success' => false,
        'message' =>
            $invalidLoginMessage
    ], 401);
}


/*
|--------------------------------------------------------------------------
| Successful Login
|--------------------------------------------------------------------------
*/

try {

    /*
     * Prevent session fixation.
     */

    session_regenerate_id(true);


    /*
     * Reset failed login attempts.
     */

    $reset =
        $pdo->prepare(
            'UPDATE users
             SET
                failed_login_attempts = 0,
                locked_until = NULL,
                last_login_at = NOW()
             WHERE id = :id'
        );


    $reset->execute([
        ':id' => $user['id']
    ]);


    /*
     * Store authenticated user session.
     */

    // $_SESSION['authenticated'] = true;

    // $_SESSION['user_id'] =
    //     (int)$user['id'];

    // $_SESSION['user_email'] =
    //     $user['email'];

    // $_SESSION['user_name'] =
    //     $user['full_name'];

    // $_SESSION['user_role'] =
    //     $user['role'];

    $_SESSION['authenticated'] = true;

$_SESSION['user_id'] =
    (int)$user['id'];

$_SESSION['user_email'] =
    $user['email'];

$_SESSION['user_name'] =
    $user['full_name'];

$_SESSION['user_role'] =
    $user['role'];

$_SESSION['login_time'] =
    time();

$_SESSION['csrf_token'] =
    bin2hex(random_bytes(32));


    /*
     * Store login timestamp.
     */

    $_SESSION['login_time'] =
        time();


    /*
     * Regenerate CSRF token after
     * authentication.
     */

    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );


    /*
     * Security event.
     */

    try {

        $event =
            $pdo->prepare(
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


        $event->execute([
            ':user_id' =>
                $user['id'],

            ':event_type' =>
                'login_success',

            ':ip_address' =>
                getClientIp(),

            ':user_agent' =>
                substr(
                    (string)(
                        $_SERVER['HTTP_USER_AGENT']
                        ?? ''
                    ),
                    0,
                    500
                )
        ]);

    } catch (Throwable $e) {

        /*
         * Do not fail login if
         * security logging fails.
         */

        error_log(
            'Research AI LOGIN SECURITY LOG ERROR: ' .
            $e->getMessage()
        );
    }


    /*
     * Success response.
     */

    jsonResponse([
        'success' => true,

        'message' =>
            'Login successful.',

        'redirect' => '../../pages/dashboard.html'  
    ]);


} catch (PDOException $e) {

    error_log(
        'Research AI LOGIN FINAL ERROR: ' .
        $e->getMessage()
    );

    jsonResponse([
        'success' => false,
        'message' =>
            'Unable to complete login right now.'
    ], 500);
}