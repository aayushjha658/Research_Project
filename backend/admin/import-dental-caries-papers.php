<?php

declare(strict_types=1);
require_once __DIR__ . '/../common/api.php';

$adminId = requireAdmin();
methodOnly('POST');
requireCsrf();

$pmids = [
    '39947783', // Abbott et al., 2025 - AI platforms in dental caries detection
    '40181403', // Luke & Rezallah, 2025 - AI caries detection systematic review/meta-analysis
    '39609513', // Uddin et al., 2025 - AI deep learning on intraoral caries images
    '40702334', // Lam et al., 2025 - YOLO for dental caries diagnosis
    '40802701', // Arzani et al., 2025 - umbrella review/meta-analysis across imaging modalities
    '41480011', // Acosta et al., 2026 issue / 2025 online - AI smartphone imaging systematic review
    '41634741', // Lai et al., 2026 - binary caries diagnosis systematic review/meta-analysis
    '41833430', // Guo et al., 2026 - proximal caries ML systematic review/meta-analysis
    '42537896', // Bittar et al., 2026 - AI dental caries umbrella review
    '42704746', // Sachdeva et al., 2026 - secondary caries AI systematic review/meta-analysis
];

function fetchPubMedXml(array $pmids): string
{
    $url = 'https://eutils.ncbi.nlm.nih.gov/entrez/eutils/efetch.fcgi?' . http_build_query([
        'db' => 'pubmed',
        'id' => implode(',', $pmids),
        'retmode' => 'xml',
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml,text/xml;q=0.9,*/*;q=0.8',
            'User-Agent: ResearchAI/1.0 (academic research platform)',
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $http < 200 || $http >= 300 || trim($body) === '') {
        throw new RuntimeException('PubMed request failed' . ($err ? ': ' . $err : '') . ' (HTTP ' . $http . ')');
    }
    return $body;
}

function xmlText(?SimpleXMLElement $node): string
{
    if (!$node) return '';
    return trim(preg_replace('/\s+/u', ' ', (string)$node));
}

function pubmedYear(SimpleXMLElement $article): ?int
{
    $dates = [
        $article->PubmedData->ArticleIdList ? null : null,
        $article->MedlineCitation->Article->Journal->JournalIssue->PubDate,
        $article->MedlineCitation->Article->ArticleDate,
    ];
    foreach ($dates as $dateNode) {
        if (!$dateNode) continue;
        $text = xmlText($dateNode);
        if (preg_match('/\b(19|20)\d{2}\b/', $text, $m)) return (int)$m[0];
    }
    return null;
}

function pubmedAbstract(SimpleXMLElement $article): string
{
    $parts = [];
    foreach ($article->MedlineCitation->Article->Abstract->AbstractText as $node) {
        $label = trim((string)$node['Label']);
        $text = xmlText($node);
        if ($text === '') continue;
        $parts[] = $label !== '' ? $label . ': ' . $text : $text;
    }
    return trim(implode(' ', $parts));
}

function pubmedAuthors(SimpleXMLElement $article): string
{
    $authors = [];
    foreach ($article->MedlineCitation->Article->AuthorList->Author as $a) {
        $last = trim((string)$a->LastName);
        $initials = trim((string)$a->Initials);
        $collective = trim((string)$a->CollectiveName);
        if ($collective !== '') {
            $authors[] = $collective;
        } elseif ($last !== '') {
            $authors[] = trim($last . ($initials !== '' ? ' ' . $initials : ''));
        }
    }
    return implode(', ', $authors);
}

try {
    $pdo = db();
    $xmlString = fetchPubMedXml($pmids);
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlString);
    if (!$xml) throw new RuntimeException('PubMed returned invalid XML.');

    $inserted = [];
    $skipped = [];
    $failed = [];

    $check = $pdo->prepare('SELECT id FROM research_papers WHERE doi = :doi OR title = :title LIMIT 1');
    $insert = $pdo->prepare('INSERT INTO research_papers (title, authors, abstract, journal, publication_year, doi, url, pdf_path) VALUES (:title, :authors, :abstract, :journal, :year, :doi, :url, NULL)');

    foreach ($xml->PubmedArticle as $article) {
        $pmid = trim((string)$article->MedlineCitation->PMID);
        $title = xmlText($article->MedlineCitation->Article->ArticleTitle);
        $abstract = pubmedAbstract($article);
        $authors = pubmedAuthors($article);
        $journal = xmlText($article->MedlineCitation->Article->Journal->Title);
        $year = pubmedYear($article);
        $doi = '';

        foreach ($article->PubmedData->ArticleIdList->ArticleId as $idNode) {
            if ((string)$idNode['IdType'] === 'doi') {
                $doi = trim((string)$idNode);
                break;
            }
        }

        if ($title === '' || $pmid === '') {
            $failed[] = ['pmid' => $pmid, 'reason' => 'Missing title or PMID'];
            continue;
        }

        $check->execute([':doi' => $doi !== '' ? $doi : '__no_doi__' . $pmid, ':title' => $title]);
        if ($check->fetch()) {
            $skipped[] = ['pmid' => $pmid, 'title' => $title, 'reason' => 'Already exists'];
            continue;
        }

        $insert->execute([
            ':title' => $title,
            ':authors' => $authors !== '' ? $authors : null,
            ':abstract' => $abstract !== '' ? $abstract : null,
            ':journal' => $journal !== '' ? $journal : null,
            ':year' => $year,
            ':doi' => $doi !== '' ? $doi : null,
            ':url' => 'https://pubmed.ncbi.nlm.nih.gov/' . rawurlencode($pmid) . '/',
        ]);

        $inserted[] = [
            'id' => (int)$pdo->lastInsertId(),
            'pmid' => $pmid,
            'title' => $title,
            'year' => $year,
            'doi' => $doi,
        ];
    }

    audit($pdo, $adminId, 'dental_caries_papers_imported');

    jsonResponse([
        'success' => true,
        'message' => 'Dental caries literature import completed.',
        'requested_pmids' => $pmids,
        'inserted_count' => count($inserted),
        'skipped_count' => count($skipped),
        'failed_count' => count($failed),
        'inserted' => $inserted,
        'skipped' => $skipped,
        'failed' => $failed,
    ]);
} catch (Throwable $e) {
    error_log('Dental caries paper import error: ' . $e->getMessage());
    jsonResponse([
        'success' => false,
        'message' => 'Dental caries paper import failed. Check server connectivity and PHP cURL/XML support.',
    ], 500);
}
