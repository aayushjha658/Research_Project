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
| Get CSRF Token
|--------------------------------------------------------------------------
*/

$csrfToken =
    $_POST['csrf_token'] ?? '';

$sessionToken =
    $_SESSION['csrf_token'] ?? '';


/*
|--------------------------------------------------------------------------
| CSRF Validation
|--------------------------------------------------------------------------
*/

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
            'Security validation failed.'
    ], 403);
}


/*
|--------------------------------------------------------------------------
| Get Current User
|--------------------------------------------------------------------------
*/

$userId =
    isset($_SESSION['user_id'])
        ? (int)$_SESSION['user_id']
        : null;


/*
|--------------------------------------------------------------------------
| Log Logout Event
|--------------------------------------------------------------------------
*/

if ($userId !== null) {

    try {

        $pdo =
            getDatabaseConnection();


        $stmt =
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


        $stmt->execute([

            ':user_id' =>
                $userId,

            ':event_type' =>
                'logout',

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
         * Logging failure should not
         * prevent logout.
         */

        error_log(
            'Research AI LOGOUT SECURITY LOG ERROR: ' .
            $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Destroy Session
|--------------------------------------------------------------------------
*/

$_SESSION = [];


/*
|--------------------------------------------------------------------------
| Delete Session Cookie
|--------------------------------------------------------------------------
*/

if (
    ini_get('session.use_cookies')
) {

    $params =
        session_get_cookie_params();


    setcookie(
        session_name(),
        '',
        [
            'expires' =>
                time() - 42000,

            'path' =>
                $params['path'],

            'domain' =>
                $params['domain'],

            'secure' =>
                $params['secure'],

            'httponly' =>
                $params['httponly'],

            'samesite' =>
                $params['samesite'] ?? 'Lax'
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Destroy PHP Session
|--------------------------------------------------------------------------
*/

session_destroy();


/*
|--------------------------------------------------------------------------
| Response
|--------------------------------------------------------------------------
*/

jsonResponse([

    'success' =>
        true,

    'message' =>
        'You have been logged out successfully.',

    'redirect' =>
        '../../pages/login.html'

]);