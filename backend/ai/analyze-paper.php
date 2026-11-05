<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';
require_once __DIR__ . '/gemini.php';


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

requireAuth();

methodOnly('GET');


/*
|--------------------------------------------------------------------------
| Validate Paper ID
|--------------------------------------------------------------------------
*/

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

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    */

    $pdo = db();


    /*
    |--------------------------------------------------------------------------
    | Fetch Research Paper
    |--------------------------------------------------------------------------
    */

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
            citation_count,
            view_count,
            pdf_path

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


    /*
    |--------------------------------------------------------------------------
    | Paper Not Found
    |--------------------------------------------------------------------------
    */

    if (!$paper) {

        jsonResponse([
            'success' => false,
            'message' => 'Research paper not found.'
        ], 404);
    }


    /*
    |--------------------------------------------------------------------------
    | Prepare Paper Information
    |--------------------------------------------------------------------------
    */

    $title = trim(
        (string) ($paper['title'] ?? '')
    );

    $authors = trim(
        (string) ($paper['authors'] ?? '')
    );

    $abstract = trim(
        (string) ($paper['abstract'] ?? '')
    );

    $journal = trim(
        (string) ($paper['journal'] ?? '')
    );

    $domain = trim(
        (string) ($paper['domain'] ?? '')
    );

    $paperType = trim(
        (string) ($paper['paper_type'] ?? '')
    );

    $year = $paper['publication_year'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | Validate Minimum Content
    |--------------------------------------------------------------------------
    */

    if ($title === '') {

        jsonResponse([
            'success' => false,
            'message' => 'Paper title is required for AI analysis.'
        ], 422);
    }


    /*
    |--------------------------------------------------------------------------
    | Create Academic AI Prompt
    |--------------------------------------------------------------------------
    */

    $prompt = <<<PROMPT

You are an expert academic research analyst.

Analyze the following research paper carefully using ONLY the information
provided below.

Do not invent experimental results, datasets, sample sizes, performance
metrics, or conclusions that are not supported by the provided information.

If information is insufficient for a section, clearly state that the
information is not available from the provided metadata and abstract.

RESEARCH PAPER INFORMATION

Title:
{$title}

Authors:
{$authors}

Abstract:
{$abstract}

Journal:
{$journal}

Research Domain:
{$domain}

Paper Type:
{$paperType}

Publication Year:
{$year}


Return ONLY valid JSON.

Use exactly this JSON structure:

{
  "paper_summary": "string",

  "key_concepts": [
    "string"
  ],

  "research_objectives": [
    "string"
  ],

  "methodology": "string",

  "key_findings": [
    "string"
  ],

  "research_gaps": [
    "string"
  ],

  "future_scope": [
    "string"
  ]
}

Requirements:

1. paper_summary:
   Write a concise academic summary.

2. key_concepts:
   Identify the most important research concepts.

3. research_objectives:
   Identify explicit or reasonably supported objectives.

4. methodology:
   Describe the methodology only from available information.
   Do not invent details.

5. key_findings:
   Identify findings supported by the abstract.
   If findings are unavailable, state that clearly.

6. research_gaps:
   Identify realistic research gaps based on the available information.
   Clearly distinguish inferred gaps from explicitly stated gaps.

7. future_scope:
   Suggest academically reasonable future research directions.

Return JSON only.
Do not use Markdown.
Do not use code blocks.

PROMPT;


    /*
    |--------------------------------------------------------------------------
    | Call Gemini AI
    |--------------------------------------------------------------------------
    */

    $analysis = callGemini(
        $prompt
    );


    /*
    |--------------------------------------------------------------------------
    | Normalize AI Response
    |--------------------------------------------------------------------------
    */

    $requiredFields = [

        'paper_summary',
        'key_concepts',
        'research_objectives',
        'methodology',
        'key_findings',
        'research_gaps',
        'future_scope'

    ];


    foreach ($requiredFields as $field) {

        if (!array_key_exists($field, $analysis)) {

            $analysis[$field] =
                in_array(
                    $field,
                    [
                        'key_concepts',
                        'research_objectives',
                        'key_findings',
                        'research_gaps',
                        'future_scope'
                    ],
                    true
                )
                    ? []
                    : 'Information not available.';
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Ensure Array Fields
    |--------------------------------------------------------------------------
    */

    $arrayFields = [

        'key_concepts',
        'research_objectives',
        'key_findings',
        'research_gaps',
        'future_scope'

    ];


    foreach ($arrayFields as $field) {

        if (!is_array($analysis[$field])) {

            $analysis[$field] = [
                (string) $analysis[$field]
            ];
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Final Response
    |--------------------------------------------------------------------------
    */

    jsonResponse([

        'success' => true,

        'paper' => [

            'id' => (int) $paper['id'],

            'title' => $title,

            'authors' => $authors,

            'abstract' => $abstract,

            'journal' => $journal,

            'domain' => $domain,

            'paper_type' => $paperType,

            'publication_year' =>
                $year !== ''
                    ? (int) $year
                    : null

        ],

        'analysis' => $analysis

    ]);


} catch (Throwable $error) {

    /*
    |--------------------------------------------------------------------------
    | Log Internal Error
    |--------------------------------------------------------------------------
    */

    error_log(
        'Gemini Paper Analysis Error: ' .
        $error->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | Safe Error Response
    |--------------------------------------------------------------------------
    */

    jsonResponse([

        'success' => false,

        'message' =>
             $error->getMessage(),

        'debug_type' => get_class($error)

    ], 500);
}