<?php
declare(strict_types=1);

/**
 * Research AI
 * Professional AI Analysis PDF Export
 *
 * File:
 * backend/ai/export-analysis.php
 */

require_once __DIR__ . '/../common/api.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/gemini.php';


/*
|--------------------------------------------------------------------------
| Composer Autoload
|--------------------------------------------------------------------------
*/

$autoloadPath = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!file_exists($autoloadPath)) {
    http_response_code(500);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'success' => false,
        'message' => 'Composer autoload.php not found.'
    ], JSON_UNESCAPED_UNICODE); 

    exit;
}

require_once $autoloadPath;


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

requireAuth();


/*
|--------------------------------------------------------------------------
| Request Method
|--------------------------------------------------------------------------
*/

methodOnly('GET');


/*
|--------------------------------------------------------------------------
| Paper ID
|--------------------------------------------------------------------------
*/

$paperId = filter_input(
    INPUT_GET,
    'id',
    FILTER_VALIDATE_INT
);

if (!$paperId || $paperId < 1) {

    http_response_code(400);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Invalid paper ID.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Database
|--------------------------------------------------------------------------
*/

try {

    $pdo = db();

    $stmt = $pdo->prepare("
        SELECT
            id,
            title,
            authors,
            abstract,
            journal,
            domain,
            paper_type,
            access_type,
            citation_count,
            view_count,
            publication_year,
            doi,
            url,
            pdf_path
        FROM research_papers
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        ':id' => $paperId
    ]);

    $paper = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paper) {

        http_response_code(404);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode([
            'success' => false,
            'message' => 'Research paper not found.'
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }

} catch (Throwable $e) {

    error_log(
        'PDF export database error: ' .
        $e->getMessage()
    );

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to load paper information.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Prepare Paper Information
|--------------------------------------------------------------------------
*/

$title =
    (string)($paper['title'] ?? 'Untitled Research Paper');

$authors =
    (string)($paper['authors'] ?? 'Not available');

$abstract =
    (string)($paper['abstract'] ?? '');

$journal =
    (string)($paper['journal'] ?? 'Not available');

$domain =
    (string)($paper['domain'] ?? '');

$paperType =
    (string)($paper['paper_type'] ?? '');

$accessType =
    (string)($paper['access_type'] ?? '');

$publicationYear =
    (string)($paper['publication_year'] ?? '');

$doi =
    (string)($paper['doi'] ?? '');


/*
|--------------------------------------------------------------------------
| Generate AI Analysis Directly
|--------------------------------------------------------------------------
|
| No internal HTTP/cURL request.
| Existing gemini.php is used directly.
|
*/

$prompt = <<<PROMPT
You are an academic research-analysis assistant.

Analyze the following research paper information and return ONLY valid JSON.

IMPORTANT RULES:

1. Do not invent datasets, sample sizes, experiments, statistical values,
   performance metrics, results, or methodology details that are not provided.
2. If information is unavailable, explicitly say that it is not available
   from the supplied paper information.
3. Keep the analysis academically professional.
4. Base the analysis only on the supplied title, authors, abstract,
   journal, domain, paper type, and publication year.
5. Return valid JSON only.
6. Arrays must contain concise but meaningful academic points.

Required JSON structure:

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

PAPER INFORMATION

Title:
{$title}

Authors:
{$authors}

Abstract:
{$abstract}

Journal:
{$journal}

Domain:
{$domain}

Paper Type:
{$paperType}

Access Type:
{$accessType}

Publication Year:
{$publicationYear}

DOI:
{$doi}

Now generate the JSON analysis.
PROMPT;


/*
|--------------------------------------------------------------------------
| Gemini Request
|--------------------------------------------------------------------------
*/

try {

    $analysis = callGemini($prompt);

} catch (Throwable $e) {

    error_log(
        'PDF Gemini analysis error: ' .
        $e->getMessage()
    );

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Unable to generate AI analysis for PDF.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Validate Gemini Response
|--------------------------------------------------------------------------
*/

if (!is_array($analysis)) {

    http_response_code(500);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    echo json_encode([
        'success' => false,
        'message' => 'Invalid AI analysis response.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

function pdfClean(?string $text): string
{
    $text = (string)$text;

    $text = html_entity_decode(
        $text,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    $text = strip_tags($text);

    /*
     * FPDF core fonts use Windows-1252.
     */
    $converted = @iconv(
        'UTF-8',
        'Windows-1252//TRANSLIT//IGNORE',
        $text
    );

    if ($converted !== false) {
        $text = $converted;
    }

    return trim($text);
}


function pdfArray(mixed $value): array
{
    if (!is_array($value)) {

        if (
            $value === null ||
            trim((string)$value) === ''
        ) {
            return [];
        }

        return [
            (string)$value
        ];
    }

    $result = [];

    foreach ($value as $item) {

        if (is_scalar($item)) {

            $item = trim(
                (string)$item
            );

            if ($item !== '') {
                $result[] = $item;
            }
        }
    }

    return $result;
}


/*
|--------------------------------------------------------------------------
| AI Analysis Fields
|--------------------------------------------------------------------------
*/

$summary =
    (string)(
        $analysis['paper_summary']
        ?? 'No AI summary available.'
    );


$objectives =
    pdfArray(
        $analysis['research_objectives']
        ?? []
    );


$methodology =
    (string)(
        $analysis['methodology']
        ?? 'No methodology information available.'
    );


$keyConcepts =
    pdfArray(
        $analysis['key_concepts']
        ?? []
    );


$keyFindings =
    pdfArray(
        $analysis['key_findings']
        ?? []
    );


$researchGaps =
    pdfArray(
        $analysis['research_gaps']
        ?? []
    );


$futureScope =
    pdfArray(
        $analysis['future_scope']
        ?? []
    );


/*
|--------------------------------------------------------------------------
| Professional PDF Class
|--------------------------------------------------------------------------
*/

class ResearchAIPDF extends \FPDF
{
    public string $reportTitle =
        'Research AI - AI Analysis Report';


    /*
    |--------------------------------------------------------------------------
    | Header
    |--------------------------------------------------------------------------
    */

    public function Header(): void
    {
        $this->SetDrawColor(
            210,
            215,
            220
        );

        $this->SetLineWidth(
            0.3
        );

        $this->Line(
            15,
            14,
            $this->GetPageWidth() - 15,
            14
        );

        $this->SetY(7);

        $this->SetFont(
            'Arial',
            'B',
            9
        );

        $this->SetTextColor(
            70,
            70,
            70
        );

        $this->Cell(
            0,
            5,
            pdfClean($this->reportTitle),
            0,
            1,
            'L'
        );

        $this->SetTextColor(
            0,
            0,
            0
        );

        $this->SetY(20);
    }


    /*
    |--------------------------------------------------------------------------
    | Footer
    |--------------------------------------------------------------------------
    */

    public function Footer(): void
    {
        $this->SetY(-17);

        $this->SetDrawColor(
            210,
            215,
            220
        );

        $this->Line(
            15,
            $this->GetY(),
            $this->GetPageWidth() - 15,
            $this->GetY()
        );

        $this->SetY(-13);

        $this->SetFont(
            'Arial',
            '',
            8
        );

        $this->SetTextColor(
            110,
            110,
            110
        );

        $this->Cell(
            95,
            5,
            'Generated by Research AI',
            0,
            0,
            'L'
        );

        $this->Cell(
            95,
            5,
            'Page ' . $this->PageNo(),
            0,
            0,
            'R'
        );

        $this->SetTextColor(
            0,
            0,
            0
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Section Title
    |--------------------------------------------------------------------------
    */

    public function sectionTitle(
        string $title
    ): void {

        if ($this->GetY() > 250) {
            $this->AddPage();
        }

        $this->Ln(5);

        $this->SetFont(
            'Arial',
            'B',
            13
        );

        $this->SetTextColor(
            35,
            55,
            75
        );

        $this->Cell(
            0,
            8,
            pdfClean($title),
            0,
            1,
            'L'
        );

        $this->SetDrawColor(
            80,
            110,
            140
        );

        $this->SetLineWidth(
            0.5
        );

        $this->Line(
            15,
            $this->GetY(),
            $this->GetPageWidth() - 15,
            $this->GetY()
        );

        $this->Ln(3);

        $this->SetTextColor(
            0,
            0,
            0
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Paragraph
    |--------------------------------------------------------------------------
    */

    public function paragraph(
        string $text
    ): void {

        $text = pdfClean($text);

        if ($text === '') {
            return;
        }

        $this->SetFont(
            'Arial',
            '',
            10
        );

        $this->SetTextColor(
            40,
            40,
            40
        );

        $this->MultiCell(
            0,
            5.8,
            $text,
            0,
            'J'
        );

        $this->Ln(2);
    }


    /*
    |--------------------------------------------------------------------------
    | Bullet
    |--------------------------------------------------------------------------
    */

    public function bullet(
        string $text,
        int $number = 0
    ): void {

        $text = pdfClean($text);

        if ($text === '') {
            return;
        }

        $this->SetFont(
            'Arial',
            '',
            10
        );

        $prefix =
            $number > 0
            ? $number . '. '
            : chr(149) . ' ';

        $this->SetX(18);

        $this->MultiCell(
            177,
            5.8,
            $prefix . $text,
            0,
            'L'
        );

        $this->Ln(1);
    }


    /*
    |--------------------------------------------------------------------------
    | Metadata Row
    |--------------------------------------------------------------------------
    */

    public function metadataRow(
        string $label,
        string $value
    ): void {

        $this->SetFont(
            'Arial',
            'B',
            9
        );

        $this->SetTextColor(
            70,
            70,
            70
        );

        $this->Cell(
            42,
            6,
            pdfClean($label),
            0,
            0,
            'L'
        );

        $this->SetFont(
            'Arial',
            '',
            9
        );

        $this->SetTextColor(
            25,
            25,
            25
        );

        $this->MultiCell(
            0,
            6,
            pdfClean($value),
            0,
            'L'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Create PDF
|--------------------------------------------------------------------------
*/

$pdf = new ResearchAIPDF(
    'P',
    'mm',
    'A4'
);


$pdf->AliasNbPages();


/*
|--------------------------------------------------------------------------
| PDF Metadata
|--------------------------------------------------------------------------
*/

$pdf->SetCreator(
    'Research AI'
);

$pdf->SetAuthor(
    'Research AI'
);

$pdf->SetTitle(
    pdfClean($title) .
    ' - AI Analysis'
);

$pdf->SetSubject(
    'AI-generated research paper analysis'
);

$pdf->SetKeywords(
    'Research AI, Artificial Intelligence, Research Analysis'
);


/*
|--------------------------------------------------------------------------
| Page Configuration
|--------------------------------------------------------------------------
*/

$pdf->SetMargins(
    15,
    24,
    15
);

$pdf->SetAutoPageBreak(
    true,
    20
);

$pdf->AddPage();


/*
|--------------------------------------------------------------------------
| Report Title
|--------------------------------------------------------------------------
*/

$pdf->SetFont(
    'Arial',
    'B',
    20
);

$pdf->SetTextColor(
    25,
    55,
    80
);

$pdf->MultiCell(
    0,
    9,
    pdfClean($title),
    0,
    'L'
);

$pdf->Ln(3);


$pdf->SetFont(
    'Arial',
    '',
    10
);

$pdf->SetTextColor(
    90,
    90,
    90
);

$pdf->Cell(
    0,
    5.5,
    'AI-Powered Research Analysis Report',
    0,
    1,
    'L'
);

$pdf->Ln(8);


/*
|--------------------------------------------------------------------------
| Paper Metadata Box
|--------------------------------------------------------------------------
*/

$boxY = $pdf->GetY();


$pdf->SetFillColor(
    245,
    247,
    249
);

$pdf->SetDrawColor(
    215,
    220,
    225
);


$pdf->Rect(
    15,
    $boxY,
    180,
    61,
    'DF'
);


$pdf->SetXY(
    20,
    $boxY + 5
);


$pdf->metadataRow(
    'Authors',
    $authors
);


$pdf->metadataRow(
    'Journal',
    $journal
);


if ($publicationYear !== '') {

    $pdf->metadataRow(
        'Publication Year',
        $publicationYear
    );
}


if ($domain !== '') {

    $pdf->metadataRow(
        'Domain',
        $domain
    );
}


if ($paperType !== '') {

    $pdf->metadataRow(
        'Paper Type',
        $paperType
    );
}


if ($accessType !== '') {

    $pdf->metadataRow(
        'Access',
        $accessType
    );
}


if ($doi !== '') {

    $pdf->metadataRow(
        'DOI',
        $doi
    );
}


$pdf->SetY(
    $boxY + 66
);


/*
|--------------------------------------------------------------------------
| Generated Date
|--------------------------------------------------------------------------
*/

$pdf->SetFont(
    'Arial',
    'I',
    8
);

$pdf->SetTextColor(
    110,
    110,
    110
);

$pdf->Cell(
    0,
    5,
    'Report generated: ' .
    date('d M Y, h:i A'),
    0,
    1,
    'L'
);

$pdf->SetTextColor(
    0,
    0,
    0
);


/*
|--------------------------------------------------------------------------
| Original Abstract
|--------------------------------------------------------------------------
*/

if (trim($abstract) !== '') {

    $pdf->sectionTitle(
        'Original Paper Abstract'
    );

    $pdf->paragraph(
        $abstract
    );
}


/*
|--------------------------------------------------------------------------
| 1. AI-Generated Summary
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '1. AI-Generated Paper Summary'
);

$pdf->paragraph(
    $summary
);


/*
|--------------------------------------------------------------------------
| 2. Research Objectives
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '2. Research Objectives'
);


if (count($objectives) === 0) {

    $pdf->paragraph(
        'No specific research objectives were available from the supplied paper information.'
    );

} else {

    foreach (
        $objectives
        as $index => $objective
    ) {

        $pdf->bullet(
            $objective,
            $index + 1
        );
    }
}


/*
|--------------------------------------------------------------------------
| 3. Methodology
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '3. Methodology'
);

$pdf->paragraph(
    $methodology
);


/*
|--------------------------------------------------------------------------
| 4. Key Concepts
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '4. Key Concepts'
);


if (count($keyConcepts) === 0) {

    $pdf->paragraph(
        'No key concepts were identified.'
    );

} else {

    foreach (
        $keyConcepts
        as $concept
    ) {

        $pdf->bullet(
            $concept
        );
    }
}


/*
|--------------------------------------------------------------------------
| 5. Key Findings
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '5. Key Findings'
);


if (count($keyFindings) === 0) {

    $pdf->paragraph(
        'Specific findings were not available from the supplied paper information.'
    );

} else {

    foreach (
        $keyFindings
        as $index => $finding
    ) {

        $pdf->bullet(
            $finding,
            $index + 1
        );
    }
}


/*
|--------------------------------------------------------------------------
| 6. Research Gaps
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '6. Research Gaps'
);


if (count($researchGaps) === 0) {

    $pdf->paragraph(
        'No research gaps were identified.'
    );

} else {

    foreach (
        $researchGaps
        as $index => $gap
    ) {

        $pdf->bullet(
            $gap,
            $index + 1
        );
    }
}


/*
|--------------------------------------------------------------------------
| 7. Future Research Scope
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '7. Future Research Scope'
);


if (count($futureScope) === 0) {

    $pdf->paragraph(
        'No future research scope was identified.'
    );

} else {

    foreach (
        $futureScope
        as $index => $future
    ) {

        $pdf->bullet(
            $future,
            $index + 1
        );
    }
}


/*
|--------------------------------------------------------------------------
| 8. AI Analysis Note
|--------------------------------------------------------------------------
*/

$pdf->sectionTitle(
    '8. AI Analysis Note'
);

$pdf->paragraph(
    'This report contains AI-generated analysis based on the research paper information available in the Research AI system. Where detailed experimental data, datasets, statistical results, or methodological information were not available in the supplied metadata or abstract, the analysis does not assume or invent such information.'
);


/*
|--------------------------------------------------------------------------
| Filename
|--------------------------------------------------------------------------
*/

$safeTitle = preg_replace(
    '/[^A-Za-z0-9_-]+/',
    '_',
    pdfClean($title)
);


$safeTitle = trim(
    (string)$safeTitle,
    '_'
);


if ($safeTitle === '') {
    $safeTitle = 'research-paper';
}


$filename =
    $safeTitle .
    '_AI_Analysis.pdf';


/*
|--------------------------------------------------------------------------
| Clean Output Buffer
|--------------------------------------------------------------------------
*/

while (ob_get_level() > 0) {
    ob_end_clean();
}


/*
|--------------------------------------------------------------------------
| PDF Headers
|--------------------------------------------------------------------------
*/

header(
    'Content-Type: application/pdf'
);

header(
    'Content-Disposition: attachment; filename="' .
    $filename .
    '"'
);

header(
    'Cache-Control: private, max-age=0, must-revalidate'
);

header(
    'Pragma: public'
);


/*
|--------------------------------------------------------------------------
| Output PDF
|--------------------------------------------------------------------------
*/

$pdf->Output(
    'D',
    $filename
);

exit;