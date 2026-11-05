<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Load .env File
|--------------------------------------------------------------------------
*/

function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {

        $line = trim($line);

        // Ignore comments and empty lines
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        // Split KEY=VALUE
        $position = strpos($line, '=');

        if ($position === false) {
            continue;
        }

        $key = trim(substr($line, 0, $position));
        $value = trim(substr($line, $position + 1));

        // Remove quotes if present
        if (
            strlen($value) >= 2 &&
            (
                ($value[0] === '"' && substr($value, -1) === '"') ||
                ($value[0] === "'" && substr($value, -1) === "'")
            )
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '') {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load Environment Variables
|--------------------------------------------------------------------------
|
| .env file location:
| backend/config/.env
|
*/

loadEnv(__DIR__ . '/.env');


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

function getDatabaseConnection(): PDO
{
    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3307';
    $name = getenv('DB_NAME') ?: 'research_ai';
    $user = getenv('DB_USER') ?: 'root';

    $password = getenv('DB_PASSWORD') !== false
        ? (string) getenv('DB_PASSWORD')
        : '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    try {

        return new PDO(
            $dsn,
            $user,
            $password,
            $options
        );

    } catch (PDOException $e) {

        error_log(
            'Database connection failed: ' .
            $e->getMessage()
        );

        jsonResponseSafe([
            'success' => false,
            'message' => 'Unable to connect to the database.'
        ], 500);
    }
}


/*
|--------------------------------------------------------------------------
| Safe JSON Response
|--------------------------------------------------------------------------
*/

function jsonResponseSafe(
    array $data,
    int $status = 200
): never {

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}