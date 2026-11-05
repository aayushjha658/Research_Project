<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';

$userId = requireAuth();

methodOnly('GET', 'POST', 'DELETE');

$pdo = db();


/*
|--------------------------------------------------------------------------
| Ensure CSRF Token Exists
|--------------------------------------------------------------------------
*/

if (
    empty($_SESSION['csrf_token']) ||
    !is_string($_SESSION['csrf_token'])
) {
    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| GET BOOKMARKS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $s = $pdo->prepare(
        'SELECT
            b.id AS bookmark_id,
            b.paper_id,
            b.created_at AS bookmarked_at,

            p.id,
            p.title,
            p.authors,
            p.abstract,
            p.journal,
            p.publication_year,
            p.doi,
            p.url,
            p.pdf_path,
            p.citation_count,
            p.view_count

        FROM bookmarks b

        INNER JOIN research_papers p
            ON p.id = b.paper_id

        WHERE b.user_id = :uid

        ORDER BY b.created_at DESC'
    );

    $s->execute([
        ':uid' => $userId
    ]);

    jsonResponse([
        'success' => true,

        /*
        |--------------------------------------------------------------------------
        | Send CSRF token to authenticated frontend
        |--------------------------------------------------------------------------
        */

        'csrf_token' =>
            $_SESSION['csrf_token'],

        'bookmarks' =>
            $s->fetchAll(PDO::FETCH_ASSOC)
    ]);
}


/*
|--------------------------------------------------------------------------
| CSRF Protection For POST / DELETE
|--------------------------------------------------------------------------
*/

requireCsrf();

$d = requestData();

$paperId = (int)(
    $d['paper_id'] ??
    $_GET['paper_id'] ??
    0
);

if ($paperId < 1) {

    jsonResponse([
        'success' => false,
        'message' => 'Valid paper_id is required.'
    ], 422);
}


/*
|--------------------------------------------------------------------------
| REMOVE BOOKMARK
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'DELETE' ||
    !empty($d['remove'])
) {

    $s = $pdo->prepare(
        'DELETE FROM bookmarks

        WHERE user_id = :uid
        AND paper_id = :pid'
    );

    $s->execute([
        ':uid' => $userId,
        ':pid' => $paperId
    ]);


    if ($s->rowCount() === 0) {

        jsonResponse([
            'success' => false,
            'message' => 'Bookmark not found.'
        ], 404);
    }


    jsonResponse([
        'success' => true,
        'bookmarked' => false,
        'message' => 'Bookmark removed successfully.'
    ]);
}


/*
|--------------------------------------------------------------------------
| ADD BOOKMARK
|--------------------------------------------------------------------------
*/

$s = $pdo->prepare(
    'INSERT IGNORE INTO bookmarks
    (
        user_id,
        paper_id
    )

    SELECT
        :uid,
        id

    FROM research_papers

    WHERE id = :pid'
);

$s->execute([
    ':uid' => $userId,
    ':pid' => $paperId
]);


if ($s->rowCount() === 0) {

    jsonResponse([
        'success' => false,
        'message' =>
            'Paper not found or already bookmarked.'
    ], 409);
}


jsonResponse([
    'success' => true,
    'bookmarked' => true,
    'message' => 'Paper bookmarked successfully.'
]);