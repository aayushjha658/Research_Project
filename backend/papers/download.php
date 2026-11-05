<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';

/*
|--------------------------------------------------------------------------
| Allow GET request
|--------------------------------------------------------------------------
*/

methodOnly('GET');

$paperId = isset($_GET['id'])
    ? (int)$_GET['id']
    : 0;

if ($paperId < 1) {

    http_response_code(422);

    exit('Valid paper ID is required.');
}

/*
|--------------------------------------------------------------------------
| Database connection
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    /*
    |--------------------------------------------------------------------------
    | Get paper PDF path from database
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare('
        SELECT
            id,
            title,
            pdf_path
        FROM research_papers
        WHERE id = :id
        LIMIT 1
    ');

    $stmt->execute([
        ':id' => $paperId
    ]);

    $paper = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paper) {

        http_response_code(404);

        exit('Paper not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | Check PDF path
    |--------------------------------------------------------------------------
    */

    if (
        empty($paper['pdf_path'])
    ) {

        http_response_code(404);

        exit('PDF is not available for this paper.');
    }

    /*
    |--------------------------------------------------------------------------
    | Project root
    |--------------------------------------------------------------------------
    */

    $projectRoot = dirname(__DIR__, 2);

    /*
    |--------------------------------------------------------------------------
    | Build actual PDF path
    |--------------------------------------------------------------------------
    */

    $pdfPath = $projectRoot .
        DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $paper['pdf_path']
        );

    /*
    |--------------------------------------------------------------------------
    | Security + file validation
    |--------------------------------------------------------------------------
    */

    $realProjectRoot = realpath($projectRoot);

    $realPdfPath = realpath($pdfPath);

    if (
        $realPdfPath === false ||
        $realProjectRoot === false ||
        strpos($realPdfPath, $realProjectRoot) !== 0
    ) {

        http_response_code(404);

        exit('PDF file not found.');
    }

    if (!is_file($realPdfPath)) {

        http_response_code(404);

        exit('PDF file not found.');
    }

    /*
    |--------------------------------------------------------------------------
    | Safe download filename
    |--------------------------------------------------------------------------
    */

    $fileName = basename($realPdfPath);

    /*
    |--------------------------------------------------------------------------
    | Force PDF download
    |--------------------------------------------------------------------------
    */

    header('Content-Type: application/pdf');

    header(
        'Content-Disposition: attachment; filename="' .
        $fileName .
        '"'
    );

    header('Content-Length: ' . filesize($realPdfPath));

    header('Cache-Control: private');

    header('X-Content-Type-Options: nosniff');

    /*
    |--------------------------------------------------------------------------
    | Output PDF
    |--------------------------------------------------------------------------
    */

    readfile($realPdfPath);

    exit;

} catch (Throwable $error) {

    error_log(
        'PDF Download Error: ' .
        $error->getMessage()
    );

    http_response_code(500);

    exit('Unable to download PDF.');
}