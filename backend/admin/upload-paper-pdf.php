<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';

$adminId = requireAdmin();

methodOnly('POST');

requireCsrf();

if (
    !isset($_FILES['pdf']) ||
    !is_array($_FILES['pdf'])
) {
    jsonResponse([
        'success' => false,
        'message' => 'PDF file is required.'
    ], 422);
}

$file = $_FILES['pdf'];

if (
    !isset($file['error']) ||
    $file['error'] !== UPLOAD_ERR_OK
) {
    jsonResponse([
        'success' => false,
        'message' => 'PDF upload failed.'
    ], 422);
}

$maxSize = 20 * 1024 * 1024;

if ((int)$file['size'] > $maxSize) {

    jsonResponse([
        'success' => false,
        'message' => 'PDF file size must not exceed 20 MB.'
    ], 422);
}

if ((int)$file['size'] <= 0) {

    jsonResponse([
        'success' => false,
        'message' => 'Invalid PDF file.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Verify MIME type
|--------------------------------------------------------------------------
*/

$finfo = new finfo(FILEINFO_MIME_TYPE);

$mimeType = $finfo->file($file['tmp_name']);

$allowedMimeTypes = [
    'application/pdf'
];

if (!in_array($mimeType, $allowedMimeTypes, true)) {

    jsonResponse([
        'success' => false,
        'message' => 'Only valid PDF files are allowed.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Verify PDF signature
|--------------------------------------------------------------------------
*/

$handle = fopen($file['tmp_name'], 'rb');

if ($handle === false) {

    jsonResponse([
        'success' => false,
        'message' => 'Unable to validate PDF.'
    ], 422);
}

$header = fread($handle, 4);

fclose($handle);

if ($header !== '%PDF') {

    jsonResponse([
        'success' => false,
        'message' => 'Invalid PDF file.'
    ], 422);
}

/*
|--------------------------------------------------------------------------
| Upload directory
|--------------------------------------------------------------------------
*/

$projectRoot = dirname(__DIR__, 2);

$uploadDirectory =
    $projectRoot .
    DIRECTORY_SEPARATOR .
    'uploads' .
    DIRECTORY_SEPARATOR .
    'papers';

if (!is_dir($uploadDirectory)) {

    if (
        !mkdir(
            $uploadDirectory,
            0755,
            true
        )
    ) {

        jsonResponse([
            'success' => false,
            'message' => 'Unable to create upload directory.'
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| Generate unique filename
|--------------------------------------------------------------------------
*/

$fileName =
    'paper_' .
    date('Ymd_His') .
    '_' .
    bin2hex(random_bytes(8)) .
    '.pdf';

$destination =
    $uploadDirectory .
    DIRECTORY_SEPARATOR .
    $fileName;

/*
|--------------------------------------------------------------------------
| Move uploaded file
|--------------------------------------------------------------------------
*/

if (
    !move_uploaded_file(
        $file['tmp_name'],
        $destination
    )
) {

    jsonResponse([
        'success' => false,
        'message' => 'Unable to save PDF file.'
    ], 500);
}

/*
|--------------------------------------------------------------------------
| Relative database path
|--------------------------------------------------------------------------
*/

$pdfPath =
    'uploads/papers/' .
    $fileName;

/*
|--------------------------------------------------------------------------
| Audit log
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    audit(
        $pdo,
        $adminId,
        'paper_pdf_uploaded'
    );

} catch (Throwable $error) {

    error_log(
        'PDF Upload Audit Error: ' .
        $error->getMessage()
    );
}

/*
|--------------------------------------------------------------------------
| Success response
|--------------------------------------------------------------------------
*/

jsonResponse([
    'success' => true,
    'message' => 'PDF uploaded successfully.',
    'pdf_path' => $pdfPath,
    'file_name' => $fileName
]);