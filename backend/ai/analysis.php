<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';

requireAuth();

methodOnly('GET');

$paperId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$paperId || $paperId < 1) {

    jsonResponse([
        'success' => false,
        'message' => 'Valid paper ID is required.'
    ], 422);
}

try {

    $pdo = db();

    $statement = $pdo->prepare(
        '
        SELECT
            id,
            title,
            authors,
            abstract,
            journal,
            domain,
            paper_type,
            access_type,
            publication_year,
            doi,
            url,
            pdf_path,
            citation_count,
            view_count,
            created_at,
            updated_at

        FROM research_papers

        WHERE id = :id

        LIMIT 1
        '
    );

    $statement->execute([
        ':id' => $paperId
    ]);

    $paper = $statement->fetch(
        PDO::FETCH_ASSOC
    );

    if (!$paper) {

        jsonResponse([
            'success' => false,
            'message' => 'Research paper not found.'
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Convert database values
    |--------------------------------------------------------------------------
    */

    $paper['id'] =
        (int)$paper['id'];

    $paper['publication_year'] =
        $paper['publication_year'] !== null
            ? (int)$paper['publication_year']
            : null;

    $paper['citation_count'] =
        (int)$paper['citation_count'];

    $paper['view_count'] =
        (int)$paper['view_count'];


    /*
    |--------------------------------------------------------------------------
    | Temporary analysis structure
    |
    | Next step: Real AI generation
    |--------------------------------------------------------------------------
    */

    $analysis = [

        'paper_summary' =>
            'Analysis is ready to be generated for this research paper.',

        'key_concepts' => [],

        'research_objectives' => [],

        'methodology' =>
            'Methodology analysis will be generated from the available paper content.',

        'key_findings' => [],

        'research_gaps' => [],

        'future_scope' => []

    ];


    jsonResponse([

        'success' => true,

        'paper' => $paper,

        'analysis' => $analysis

    ]);

} catch (PDOException $error) {

    error_log(
        'AI Analysis Error: ' .
        $error->getMessage()
    );

    jsonResponse([

        'success' => false,

        'message' =>
            'Unable to load research paper for analysis.'

    ], 500);
}