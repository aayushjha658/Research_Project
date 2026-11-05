<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';

requireAuth();
methodOnly('GET');

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    jsonResponse([
        'success' => false,
        'message' => 'Valid paper ID is required.'
    ], 422);
}

try {
    $pdo = db();

    $statement = $pdo->prepare("
        SELECT
            rp.id,
            rp.title,
            rp.authors,
            rp.abstract,
            rp.journal,
            rp.domain,
            rp.paper_type,
            rp.access_type,
            rp.citation_count,
            rp.view_count,
            rp.publication_year,
            rp.doi,
            rp.url,
            rp.pdf_path,
            rp.created_at,
            rp.updated_at,
            (
                SELECT COUNT(*)
                FROM bookmarks bm
                WHERE bm.paper_id = rp.id
            ) AS bookmark_count,
            CASE
                WHEN b.id IS NULL THEN 0
                ELSE 1
            END AS is_bookmarked
        FROM research_papers rp
        LEFT JOIN bookmarks b
            ON b.paper_id = rp.id
           AND b.user_id = :user_id
        WHERE rp.id = :id
        LIMIT 1
    ");

    $statement->execute([
        ':user_id' => (int)$_SESSION['user_id'],
        ':id' => $id
    ]);

    $paper = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$paper) {
        jsonResponse([
            'success' => false,
            'message' => 'Research paper not found.'
        ], 404);
    }

    $update = $pdo->prepare("
        UPDATE research_papers
        SET view_count = view_count + 1
        WHERE id = :id
    ");

    $update->execute([':id' => $id]);
    $paper['view_count'] = (int)$paper['view_count'] + 1;

    $paper['id'] = (int)$paper['id'];
    $paper['publication_year'] = $paper['publication_year'] !== null
        ? (int)$paper['publication_year']
        : null;
    $paper['citation_count'] = (int)$paper['citation_count'];
    $paper['view_count'] = (int)$paper['view_count'];
    $paper['bookmark_count'] = (int)$paper['bookmark_count'];
    $paper['is_bookmarked'] = (bool)$paper['is_bookmarked'];

    $paper['download_url'] = $paper['pdf_path']
        ? 'backend/papers/download.php?id=' . $paper['id']
        : null;

    $paper['analysis_url'] = 'ai-analysis.html?id=' . $paper['id'];

    jsonResponse([
        'success' => true,
        'paper' => $paper
    ]);

} catch (PDOException $error) {
    error_log('Paper Detail Error: ' . $error->getMessage());

    jsonResponse([
        'success' => false,
        'message' => 'Unable to load research paper.'
    ], 500);
}
