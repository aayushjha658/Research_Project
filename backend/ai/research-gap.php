<?php

declare(strict_types=1);

require_once __DIR__ . '/../common/api.php';
require_once __DIR__ . '/../subscription/service.php';
require_once __DIR__ . '/gemini.php';

$userId = requireAuth();
methodOnly('GET', 'POST');

/**
 * Research Gap Engine
 *
 * Pipeline:
 *   paper selection -> relevance filtering -> evidence aggregation
 *   -> Gemini cross-paper synthesis -> exact quote validation -> final response
 *
 * Important: a gap is only accepted when its supplied evidence can be traced
 * back to the selected paper abstracts. The API never treats an AI-generated
 * paraphrase as a verified quotation.
 */

function rgNormalizeText(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r", "\n", "\t", "\u{00A0}", "\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}"], [' ', ' ', ' ', ' ', "'", "'", '"', '"', '-', '-'], $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim(mb_strtolower($text));
}

function rgQuoteSupported(string $quote, string $source): bool
{
    $quote = trim($quote);
    if ($quote === '' || mb_strlen($quote) < 12) {
        return false;
    }
    return str_contains(rgNormalizeText($source), rgNormalizeText($quote));
}

function rgTokens(string $text): array
{
    $stop = [
        'the',
        'a',
        'an',
        'and',
        'or',
        'of',
        'to',
        'in',
        'on',
        'for',
        'with',
        'from',
        'by',
        'using',
        'use',
        'used',
        'into',
        'as',
        'at',
        'is',
        'are',
        'was',
        'were',
        'be',
        'been',
        'this',
        'that',
        'these',
        'those',
        'what',
        'how',
        'can',
        'may',
        'could',
        'should',
        'do',
        'does',
        'did',
        'about',
        'across',
        'based',
        'study',
        'studies',
        'research',
        'paper',
        'papers',
        'systematic',
        'review',
        'meta',
        'analysis',
        'detection',
        'detecting',
        'ai',
        'artificial',
        'intelligence'
    ];
    preg_match_all('/[a-z0-9][a-z0-9_-]{2,}/i', mb_strtolower($text), $matches);
    $tokens = [];
    foreach ($matches[0] ?? [] as $token) {
        if (!in_array($token, $stop, true)) {
            $tokens[$token] = true;
        }
    }
    return array_keys($tokens);
}

function rgRelevanceScore(string $topic, array $paper): array
{
    $title = (string)($paper['title'] ?? '');
    $abstract = (string)($paper['abstract'] ?? '');
    $domain = (string)($paper['domain'] ?? '');
    $haystack = rgNormalizeText($title . ' ' . $abstract . ' ' . $domain);
    $normalizedTopic = rgNormalizeText($topic);
    $score = 0;
    $reasons = [];

    if ($normalizedTopic !== '' && mb_strlen($normalizedTopic) >= 8 && str_contains($haystack, $normalizedTopic)) {
        $score += 8;
        $reasons[] = 'exact topic phrase';
    }

    foreach (rgTokens($topic) as $token) {
        if (str_contains(rgNormalizeText($title), $token)) {
            $score += 3;
            $reasons[] = 'title:' . $token;
        } elseif (str_contains($haystack, $token)) {
            $score += 1;
            $reasons[] = 'evidence:' . $token;
        }
    }

    if ($domain !== '' && rgTokens($topic)) {
        foreach (rgTokens($topic) as $token) {
            if (str_contains(rgNormalizeText($domain), $token)) {
                $score += 2;
                $reasons[] = 'domain:' . $token;
            }
        }
    }

    return ['score' => min($score, 30), 'reasons' => array_values(array_unique($reasons))];
}

function rgFetchPapers(PDO $pdo, string $search, array $ids = [], int $limit = 20): array
{
    $limit = max(1, $limit);
    $search = trim($search);

    /*
     * The installed project databases can differ slightly from older SQL
     * dumps. To keep Research Gap independent from optional columns, read
     * the paper rows without assuming domain/paper_type/citation_count/
     * view_count (or any other optional field) exists.
     */
    $stmt = $pdo->query('SELECT * FROM research_papers ORDER BY id DESC');
    $allRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = [];

    foreach ($allRows as $row) {
        if ($ids) {
            $id = (int)($row['id'] ?? 0);
            if (!in_array($id, $ids, true)) {
                continue;
            }
        }

        if ($search !== '') {
            $haystack = mb_strtolower(implode(' ', [
                (string)($row['title'] ?? ''),
                (string)($row['authors'] ?? ''),
                (string)($row['journal'] ?? ''),
                (string)($row['abstract'] ?? ''),
                (string)($row['doi'] ?? ''),
                (string)($row['url'] ?? ''),
            ]));
            if (!str_contains($haystack, mb_strtolower($search))) {
                continue;
            }
        }

        $rows[] = $row;
        if (count($rows) >= $limit) {
            break;
        }
    }

    foreach ($rows as &$row) {
        // Normalize optional application-level fields without requiring them
        // to exist as database columns.
        $row['domain'] = isset($row['domain']) ? (string)$row['domain'] : '';
        $row['paper_type'] = isset($row['paper_type']) ? (string)$row['paper_type'] : '';
        $row['citation_count'] = isset($row['citation_count']) ? (int)$row['citation_count'] : 0;
        $row['view_count'] = isset($row['view_count']) ? (int)$row['view_count'] : 0;
    }
    unset($row);

    return $rows;
}

function rgBuildEvidence(array $papers): array
{
    $blocks = [];
    foreach ($papers as $index => $paper) {
        $abstract = trim((string)($paper['abstract'] ?? ''));
        $blocks[] = [
            'paper_id' => (int)$paper['id'],
            'paper_index' => $index + 1,
            'title' => (string)$paper['title'],
            'authors' => (string)$paper['authors'],
            'journal' => (string)$paper['journal'],
            'publication_year' => $paper['publication_year'] !== null ? (int)$paper['publication_year'] : null,
            'doi' => (string)($paper['doi'] ?? ''),
            'abstract' => mb_substr($abstract, 0, 6000),
        ];
    }
    return $blocks;
}


function rgRequestId(): string
{
    try {
        return 'RG-' . strtoupper(bin2hex(random_bytes(5)));
    } catch (Throwable) {
        return 'RG-' . strtoupper(substr(hash('sha256', uniqid('', true)), 0, 10));
    }
}

function rgBatchSize(): int
{
    $configured = (int)(getenv('RESEARCH_GAP_BATCH_SIZE') ?: 4);
    return max(2, min(8, $configured));
}

function rgClassifyGeminiError(Throwable $e, int $httpStatus = 0): string
{
    $message = mb_strtolower($e->getMessage());

    if (in_array($httpStatus, [401, 403], true) || str_contains($message, 'api key') || str_contains($message, 'unauthorized') || str_contains($message, 'permission')) {
        return 'configuration';
    }
    if ($httpStatus === 429 || str_contains($message, '429') || str_contains($message, 'rate') || str_contains($message, 'quota')) {
        return 'rate_limit';
    }
    if (in_array($httpStatus, [408, 504], true) || str_contains($message, 'timeout') || str_contains($message, 'timed out') || str_contains($message, 'curl error 28')) {
        return 'timeout';
    }
    if ($httpStatus >= 500 && $httpStatus <= 599 || str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503') || str_contains($message, '504')) {
        return 'provider_error';
    }
    if (str_contains($message, 'json') || str_contains($message, 'decode') || str_contains($message, 'invalid response') || str_contains($message, 'empty research-gap response')) {
        return 'invalid_response';
    }
    return 'unknown';
}

function rgExtractGeminiText(array $data): string
{
    $parts = $data['candidates'][0]['content']['parts'] ?? [];
    $chunks = [];

    if (!is_array($parts)) {
        return '';
    }

    foreach ($parts as $part) {
        if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
            $chunks[] = $part['text'];
        }
    }

    return trim(implode("\n", $chunks));
}

function rgDecodeGeminiJsonText(string $text): array
{
    $text = trim($text);

    // Gemini may occasionally wrap JSON in a Markdown code fence even when
    // responseMimeType=application/json is requested. Remove only the fence.
    if (str_starts_with($text, '```')) {
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = trim($text);
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    // Last-resort extraction when the provider adds harmless leading/trailing
    // text around an otherwise valid JSON object.
    $first = strpos($text, '{');
    $last = strrpos($text, '}');
    if ($first !== false && $last !== false && $last > $first) {
        $candidate = substr($text, $first, $last - $first + 1);
        $decoded = json_decode($candidate, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    throw new RuntimeException('Gemini returned non-JSON research-gap output.');
}

function rgCallGeminiForResearchGap(string $prompt, array &$diagnostics, string $stage, int $batchNumber = 0): array
{
    if (!function_exists('geminiApiKey') || !function_exists('geminiModel')) {
        throw new RuntimeException('Gemini configuration helpers are unavailable.');
    }

    $apiKey = trim((string)geminiApiKey());
    $primaryModel = trim((string)geminiModel());
    $diagnostics['last_provider_code'] = '';
    $diagnostics['last_http_status'] = 0;
    $diagnostics['last_model'] = $primaryModel;
    $diagnostics['models_tried'] = [];
    // Keep the current configured model first, but always have a free-tier
    // fallback available. A 503 UNAVAILABLE is a provider-capacity error,
    // not an authentication error, so retrying the same model alone is not
    // sufficient for a multi-batch request.
    $configuredFallback = trim((string)(getenv('GEMINI_FALLBACK_MODEL') ?: ''));
    $freeFallbackModels = [
        'gemini-3.5-flash-lite',
        'gemini-2.5-flash-lite'
    ];

    if ($apiKey === '') {
        throw new RuntimeException('Gemini API key is not configured.');
    }
    if ($primaryModel === '') {
        throw new RuntimeException('Gemini model is not configured.');
    }

    $models = [$primaryModel];

    // An explicitly configured fallback is honored first, then free-tier
    // fallbacks are appended. This keeps the current .env unchanged while
    // making Research Gap resilient when the primary model returns 503.
    foreach (
        array_merge(
            $configuredFallback !== '' ? [$configuredFallback] : [],
            $freeFallbackModels
        ) as $candidateModel
    ) {
        $candidateModel = trim((string)$candidateModel);
        if ($candidateModel !== '' && !in_array($candidateModel, $models, true)) {
            $models[] = $candidateModel;
        }
    }

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $prompt]
                ]
            ]
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'maxOutputTokens' => 3500
        ]
    ];

    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Unable to prepare Gemini research-gap request.');
    }

    $lastSafeError = 'Gemini request failed.';
    $lastHttpStatus = 0;
    $lastProviderCode = '';

    foreach ($models as $modelIndex => $model) {
        // Primary model: two attempts. Fallback models: one attempt each.
        // For transient 503/429/5xx responses, use exponential backoff before
        // moving to the next model. Google explicitly recommends this pattern.
        $attempts = $modelIndex === 0 ? 2 : 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $diagnostics['gemini_attempts'] = (int)($diagnostics['gemini_attempts'] ?? 0) + 1;

            $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
                . rawurlencode($model) . ':generateContent';

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize Gemini connection.');
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey
                ],
                CURLOPT_POSTFIELDS => $json
            ]);

            $raw = curl_exec($ch);
            $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $diagnostics['last_model'] = $model;
            $diagnostics['last_http_status'] = $httpStatus;
            if (!in_array($model, $diagnostics['models_tried'], true)) {
                $diagnostics['models_tried'][] = $model;
            }

            if ($raw === false) {
                $lastSafeError = 'Gemini connection failed: ' . ($curlError ?: 'unknown cURL error');
                $lastHttpStatus = 0;
                if ($attempt < $attempts) {
                    usleep((int)(1500000 * (2 ** ($attempt - 1))));
                    continue;
                }
                continue;
            }

            $provider = json_decode($raw, true);
            if (!is_array($provider)) {
                $lastSafeError = 'Gemini returned an invalid JSON HTTP response.';
                $lastHttpStatus = $httpStatus;
                if ($attempt < $attempts) {
                    usleep((int)(1500000 * (2 ** ($attempt - 1))));
                    continue;
                }
                continue;
            }

            if ($httpStatus < 200 || $httpStatus >= 300) {
                $providerCode = (string)($provider['error']['status'] ?? $provider['error']['code'] ?? '');
                $providerMessage = trim((string)($provider['error']['message'] ?? 'Gemini API request failed.'));
                $lastProviderCode = $providerCode;
                $lastHttpStatus = $httpStatus;
                $lastSafeError = 'Gemini API error: ' . mb_substr($providerMessage, 0, 400);

                $transient = in_array($httpStatus, [408, 429, 500, 502, 503, 504], true);
                if ($transient && $attempt < $attempts) {
                    usleep((int)(1500000 * (2 ** ($attempt - 1))));
                    continue;
                }
                if ($transient && $modelIndex < count($models) - 1) {
                    break;
                }
                continue;
            }

            $text = rgExtractGeminiText($provider);
            if ($text === '') {
                $finishReason = (string)($provider['candidates'][0]['finishReason'] ?? '');
                $lastSafeError = 'Gemini returned an empty research-gap response.'
                    . ($finishReason !== '' ? ' Finish reason: ' . $finishReason : '');
                $lastHttpStatus = $httpStatus;
                if ($attempt < $attempts) {
                    usleep((int)(1500000 * (2 ** ($attempt - 1))));
                    continue;
                }
                continue;
            }

            try {
                return rgDecodeGeminiJsonText($text);
            } catch (Throwable $decodeError) {
                $lastSafeError = $decodeError->getMessage();
                $lastHttpStatus = $httpStatus;
                if ($attempt < $attempts) {
                    usleep((int)(1500000 * (2 ** ($attempt - 1))));
                    continue;
                }
            }
        }

        // Give the provider a short recovery window before switching models.
        // This is especially useful when two evidence batches are submitted
        // against a temporarily saturated free-tier endpoint.
        if ($modelIndex < count($models) - 1 && $lastHttpStatus >= 500) {
            usleep(1200000);
        }
    }

    $diagnostics['last_provider_code'] = $lastProviderCode;
    $diagnostics['last_http_status'] = $lastHttpStatus;
    $diagnostics['models_tried'] = array_values($models);
    throw new RuntimeException($lastSafeError);
}

function rgGeminiJson(string $prompt, array &$diagnostics, string $stage, int $batchNumber = 0): ?array
{
    $diagnostics['gemini_calls'] = (int)($diagnostics['gemini_calls'] ?? 0) + 1;

    try {
        $result = rgCallGeminiForResearchGap($prompt, $diagnostics, $stage, $batchNumber);
        if (!is_array($result)) {
            throw new RuntimeException('Invalid research-gap response type.');
        }
        return $result;
    } catch (Throwable $e) {
        $kind = rgClassifyGeminiError($e, (int)($diagnostics['last_http_status'] ?? 0));
        $diagnostics['failures'][] = [
            'stage' => $stage,
            'batch' => $batchNumber > 0 ? $batchNumber : null,
            'type' => $kind,
            'http_status' => (int)($diagnostics['last_http_status'] ?? 0),
            'provider_code' => (string)($diagnostics['last_provider_code'] ?? ''),
            'model' => (string)($diagnostics['last_model'] ?? ''),
            'attempts' => (int)($diagnostics['gemini_attempts'] ?? 0),
        ];
        error_log(sprintf(
            'Research gap Gemini failure request=%s stage=%s batch=%d type=%s http=%d provider=%s model=%s error=%s',
            (string)$diagnostics['request_id'],
            $stage,
            $batchNumber,
            $kind,
            (int)($diagnostics['last_http_status'] ?? 0),
            (string)($diagnostics['last_provider_code'] ?? ''),
            (string)($diagnostics['last_model'] ?? ''),
            $e->getMessage()
        ));
        return null;
    }
}

function rgChunkPapers(array $papers, int $size): array
{
    return $papers ? array_chunk($papers, max(2, $size)) : [];
}

function rgBuildBatchPrompt(string $topic, array $evidenceBlocks, int $batchNumber, int $totalBatches): string
{
    $base = rgBuildPrompt($topic, $evidenceBlocks);
    return "BATCH {$batchNumber} OF {$totalBatches}. Analyze only this evidence batch.\n\n{$base}\n\nDo not assume this batch contains all selected papers. Cross-paper claims are allowed only among papers actually present in this batch.";
}

function rgBuildSynthesisPrompt(string $topic, array $validatedBatchGaps, array $papers): string
{
    $paperIndex = [];
    foreach ($papers as $paper) {
        $paperIndex[(int)$paper['id']] = [
            'id' => (int)$paper['id'],
            'title' => (string)$paper['title'],
            'abstract' => mb_substr((string)($paper['abstract'] ?? ''), 0, 6000),
        ];
    }
    $gapPayload = json_encode($validatedBatchGaps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $paperPayload = json_encode(array_values($paperIndex), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return <<<PROMPT
You are the final cross-paper research-gap synthesis engine.

RESEARCH TOPIC:
{$topic}

You are given validated candidate gaps from independent evidence batches plus the original supplied paper abstracts. Do not use outside knowledge.

VALIDATED BATCH CANDIDATES:
{$gapPayload}

ORIGINAL PAPER EVIDENCE:
{$paperPayload}

TASK:
Synthesize defensible research gaps across ALL supplied papers. Prefer genuine cross-paper patterns when at least two distinct paper IDs support the same issue. Do not manufacture a cross-paper pattern.

STRICT EVIDENCE RULES:
1. Every evidence item must use an exact verbatim quote from the corresponding supplied abstract.
2. Quotes must be copied exactly; no paraphrasing and no ellipses.
3. A cross-paper gap must have evidence from at least two distinct paper IDs.
4. Never infer that a full paper lacks something merely because its abstract does not mention it. Use cautious wording such as "the supplied evidence does not report...".
5. Keep only gaps traceable to supplied evidence.
6. Do not invent datasets, populations, metrics, architectures, clinical validation, results, limitations, or conclusions.
7. Bibliographic numbers are not research findings.

Return ONLY valid JSON:
{
  "topic": "string",
  "summary": "short evidence-grounded synthesis",
  "gaps": [
    {
      "title": "short gap title",
      "category": "methodology|dataset|technology|deployment|evaluation|clinical|general",
      "priority": "high|medium|low",
      "confidence": "high|medium|low",
      "gap_basis": "explicit_limitation|cross_paper_pattern|evidence_coverage",
      "description": "careful description without overstating absence",
      "evidence_summary": "why the supplied evidence supports this gap",
      "evidence_items": [
        {"paper_id": 1, "quote": "EXACT VERBATIM QUOTE", "role": "supporting observation"}
      ],
      "opportunity": "concrete research direction framed as an opportunity",
      "research_question": "one grounded research question",
      "source_paper_ids": [1]
    }
  ],
  "recommended_direction": "one evidence-grounded research direction",
  "research_question": "one overall research question"
}
Generate 1 to 7 defensible gaps.
PROMPT;
}

function rgBuildPrompt(string $topic, array $relevantPapers): string
{
    $blocks = [];
    foreach ($relevantPapers as $paper) {
        $blocks[] = sprintf(
            "PAPER_ID: %d\nTITLE: %s\nAUTHORS: %s\nJOURNAL: %s\nYEAR: %s\nDOI: %s\nABSTRACT (source text):\n%s",
            (int)$paper['paper_id'],
            (string)$paper['title'],
            (string)$paper['authors'],
            (string)$paper['journal'],
            $paper['publication_year'] === null ? 'not reported' : (string)$paper['publication_year'],
            (string)$paper['doi'],
            (string)$paper['abstract']
        );
    }

    $evidence = implode("\n\n====================\n\n", $blocks);

    return <<<PROMPT
You are an evidence-grounded research-gap synthesis engine.

RESEARCH TOPIC:
{$topic}

ONLY use the supplied paper records below. Do not use outside knowledge, web knowledge, or unstated assumptions.

SUPPLIED PAPER EVIDENCE:
{$evidence}

TASK:
Identify defensible research gaps from the supplied evidence. Prefer cross-paper patterns when two or more papers support the same limitation, missing detail, inconsistency, uncertainty, or under-explored direction.

IMPORTANT EVIDENCE RULES:
1. A paper can support a factual observation only through its supplied title/metadata/abstract.
2. Never claim that a full paper lacks something merely because the abstract does not mention it. When evidence is only based on what the supplied abstract reports, explicitly use wording such as "the supplied evidence does not report..." or "the available abstract evidence provides limited detail on...".
3. For every gap, provide one or more EXACT VERBATIM QUOTES copied character-for-character from the supplied abstract/metadata text. Do not paraphrase quotes. Do not use ellipses inside quotes.
4. Every quote must be at least 12 characters and must be traceable to its stated PAPER_ID.
5. If a gap is cross-paper, use at least two distinct PAPER_ID values and provide an exact quote for each supporting paper.
6. Do not invent limitations, datasets, populations, metrics, architectures, clinical validations, results, or conclusions.
7. Do not use publication year, DOI, PMID, volume, issue, or other bibliographic numbers as research findings.
8. If the supplied evidence is insufficient for a defensible gap, do not manufacture one. It is acceptable to return fewer gaps.

Return ONLY valid JSON:
{
  "topic": "string",
  "summary": "short synthesis limited to supplied evidence",
  "gaps": [
    {
      "title": "short gap title",
      "category": "methodology|dataset|technology|deployment|evaluation|clinical|general",
      "priority": "high|medium|low",
      "confidence": "high|medium|low",
      "gap_basis": "explicit_limitation|cross_paper_pattern|evidence_coverage",
      "description": "careful description of the gap; do not overstate absence",
      "evidence_summary": "why the supplied evidence supports this gap",
      "evidence_items": [
        {
          "paper_id": 1,
          "quote": "EXACT VERBATIM QUOTE FROM SUPPLIED SOURCE",
          "role": "supporting observation"
        }
      ],
      "opportunity": "concrete research direction framed as an opportunity, not a proven conclusion",
      "research_question": "one research question grounded in the gap",
      "source_paper_ids": [1]
    }
  ],
  "recommended_direction": "one evidence-grounded research direction",
  "research_question": "one overall research question"
}

Generate 1 to 7 defensible gaps. When at least two relevant papers are supplied, try to identify at least one cross-paper pattern, but only if the exact evidence supports it.
PROMPT;
}

function rgNormalizeGap(array $gap, array $papersById): ?array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $gap['source_paper_ids'] ?? []), static fn(int $id): bool => $id > 0)));
    $items = is_array($gap['evidence_items'] ?? null) ? $gap['evidence_items'] : [];
    $validItems = [];
    $validIds = [];
    $issues = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $paperId = (int)($item['paper_id'] ?? 0);
        $quote = safeText($item['quote'] ?? '', 1000);
        if ($paperId <= 0 || !isset($papersById[$paperId]) || $quote === '') {
            $issues[] = 'invalid_evidence_item';
            continue;
        }
        $abstract = (string)$papersById[$paperId]['abstract'];
        if (!rgQuoteSupported($quote, $abstract)) {
            $issues[] = 'quote_not_found_in_source_' . $paperId;
            continue;
        }
        $validItems[] = [
            'paper_id' => $paperId,
            'quote' => $quote,
            'role' => safeText($item['role'] ?? 'supporting observation', 180),
            'validated' => true,
        ];
        $validIds[] = $paperId;
    }

    // Source IDs must agree with evidence items. We never allow Gemini to cite
    // a paper without an evidence item that was actually validated.
    $ids = array_values(array_unique(array_merge($ids, $validIds)));
    $ids = array_values(array_filter($ids, static fn(int $id): bool => isset($papersById[$id])));
    $ids = array_values(array_intersect($ids, $validIds));

    if (!$ids || !$validItems) {
        return null;
    }

    $basis = (string)($gap['gap_basis'] ?? 'evidence_coverage');
    if (!in_array($basis, ['explicit_limitation', 'cross_paper_pattern', 'evidence_coverage'], true)) {
        $basis = count($ids) >= 2 ? 'cross_paper_pattern' : 'evidence_coverage';
    }

    // A cross-paper claim must actually have evidence from two distinct papers.
    if ($basis === 'cross_paper_pattern' && count($ids) < 2) {
        return null;
    }

    $priority = (string)($gap['priority'] ?? 'medium');
    if (!in_array($priority, ['high', 'medium', 'low'], true)) $priority = 'medium';
    $confidence = (string)($gap['confidence'] ?? 'medium');
    if (!in_array($confidence, ['high', 'medium', 'low'], true)) $confidence = 'medium';

    return [
        'title' => safeText($gap['title'] ?? 'Research gap', 180),
        'category' => safeText($gap['category'] ?? 'general', 40),
        'priority' => $priority,
        'confidence' => $confidence,
        'gap_basis' => $basis,
        'description' => safeText($gap['description'] ?? '', 1800),
        'evidence_summary' => safeText($gap['evidence_summary'] ?? '', 1800),
        'evidence_items' => $validItems,
        'opportunity' => safeText($gap['opportunity'] ?? '', 1400),
        'research_question' => safeText($gap['research_question'] ?? '', 800),
        'source_paper_ids' => $ids,
        'validation' => [
            'supported' => true,
            'evidence_checked' => true,
            'validated_quote_count' => count($validItems),
            'validated_source_count' => count($ids),
            'issues' => $issues,
        ],
    ];
}

function rgRenderPaperSummary(array $paper, array $relevance): array
{
    return [
        'id' => (int)$paper['id'],
        'title' => (string)$paper['title'],
        'authors' => (string)$paper['authors'],
        'journal' => (string)$paper['journal'],
        'publication_year' => $paper['publication_year'] !== null ? (int)$paper['publication_year'] : null,
        'doi' => (string)($paper['doi'] ?? ''),
        'relevance_score' => (int)$relevance['score'],
        'relevance_reasons' => $relevance['reasons'],
    ];
}


function rgBuildResearchBlueprintPrompt(string $topic, array $gap, array $papers): string
{
    $evidence = [];
    foreach ($gap['evidence_items'] as $item) {
        $paperId = (int)$item['paper_id'];
        $paper = $papers[$paperId] ?? null;
        if (!$paper) continue;
        $evidence[] = sprintf(
            "PAPER_ID: %d\nTITLE: %s\nJOURNAL: %s\nYEAR: %s\nVALIDATED EVIDENCE QUOTE: %s",
            $paperId,
            (string)$paper['title'],
            (string)$paper['journal'],
            $paper['publication_year'] === null ? 'not reported' : (string)$paper['publication_year'],
            (string)$item['quote']
        );
    }

    $gapPayload = json_encode([
        'title' => $gap['title'],
        'category' => $gap['category'],
        'priority' => $gap['priority'],
        'confidence' => $gap['confidence'],
        'gap_basis' => $gap['gap_basis'],
        'description' => $gap['description'],
        'evidence_summary' => $gap['evidence_summary'],
        'opportunity' => $gap['opportunity'],
        'existing_research_question' => $gap['research_question'],
        'source_paper_ids' => $gap['source_paper_ids'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $evidenceText = implode("\n\n====================\n\n", $evidence);

    return <<<PROMPT
You are the Research AI research-design engine.

RESEARCH TOPIC:
{$topic}

VALIDATED RESEARCH GAP:
{$gapPayload}

VALIDATED SOURCE EVIDENCE:
{$evidenceText}

TASK:
Turn ONLY this validated research gap into a concrete, academically defensible research blueprint for a new study.

The blueprint must be a proposed research design, not a claim that the proposed system already exists or has been clinically validated.
Use the validated gap and evidence as the foundation. You may make reasonable methodological design choices, but clearly frame them as proposed choices to be tested.
Do not introduce factual claims about existing studies, datasets, performance numbers, populations, architectures, or clinical outcomes that are not present in the supplied evidence.
Do not invent citations, papers, results, or facts.

REQUIRED OUTPUT:
1. Research Question — one precise, testable question directly derived from the selected gap.
2. Proposed Solution — a concrete proposed system/framework addressing the gap.
3. Research Methodology — step-by-step study workflow, including data preparation, training/development, validation, and experimental design.
4. Dataset Strategy — what data should be collected/used, inclusion considerations, annotation strategy, split strategy, and external validation strategy. If exact dataset names are not supplied, describe them as proposed data requirements rather than existing resources.
5. AI Model Strategy — proposed model family/architecture, input representation, training strategy, explainability or robustness components where relevant, and why each is being proposed for this gap. Do not claim superiority without experimental evidence.
6. Evaluation Strategy — primary and secondary metrics, internal/external validation, subgroup/robustness analysis, statistical comparison where appropriate, and error analysis. Metrics are proposed evaluation choices, not reported results.
7. Expected Research Contribution — specific scientific/methodological contribution the study aims to make if the experiments support the hypothesis. Do not state it as an achieved result.

Return ONLY valid JSON in exactly this structure:
{
  "research_question": "string",
  "proposed_solution": "string",
  "research_methodology": ["step 1", "step 2"],
  "dataset_strategy": ["item 1", "item 2"],
  "ai_model_strategy": ["item 1", "item 2"],
  "evaluation_strategy": ["item 1", "item 2"],
  "expected_research_contribution": ["item 1", "item 2"],
  "evidence_basis": [1, 2]
}

Keep the output detailed enough to be useful for a real student research proposal, but concise enough for a report. Use only source paper IDs that appear in the validated evidence for evidence_basis.
PROMPT;
}

function rgNormalizeBlueprint(array $blueprint, array $allowedPaperIds): ?array
{
    $researchQuestion = safeText($blueprint['research_question'] ?? '', 1800);
    $solution = safeText($blueprint['proposed_solution'] ?? '', 2600);
    if ($researchQuestion === '' || $solution === '') return null;

    $normalizeList = static function ($value, int $maxItems, int $maxChars): array {
        if (!is_array($value)) return [];
        $out = [];
        foreach ($value as $item) {
            $text = safeText($item ?? '', $maxChars);
            if ($text !== '') $out[] = $text;
            if (count($out) >= $maxItems) break;
        }
        return $out;
    };

    $methodology = $normalizeList($blueprint['research_methodology'] ?? [], 12, 1200);
    $dataset = $normalizeList($blueprint['dataset_strategy'] ?? [], 10, 1200);
    $models = $normalizeList($blueprint['ai_model_strategy'] ?? [], 10, 1200);
    $evaluation = $normalizeList($blueprint['evaluation_strategy'] ?? [], 10, 1200);
    $contribution = $normalizeList($blueprint['expected_research_contribution'] ?? [], 8, 1200);

    if (!$methodology || !$dataset || !$models || !$evaluation || !$contribution) return null;

    $allowed = array_values(array_unique(array_map('intval', $allowedPaperIds)));
    $basis = [];
    foreach ((array)($blueprint['evidence_basis'] ?? []) as $id) {
        $id = (int)$id;
        if ($id > 0 && in_array($id, $allowed, true)) $basis[] = $id;
    }
    $basis = array_values(array_unique($basis));
    if (!$basis) $basis = $allowed;

    return [
        'research_question' => $researchQuestion,
        'proposed_solution' => $solution,
        'research_methodology' => $methodology,
        'dataset_strategy' => $dataset,
        'ai_model_strategy' => $models,
        'evaluation_strategy' => $evaluation,
        'expected_research_contribution' => $contribution,
        'evidence_basis' => $basis,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        requireAuth();
        $pdo = db();

        // `search` is only a selector filter. It is NOT the research topic
        // and must never prevent the selector from loading the full paper set.
        $search = safeText($_GET['search'] ?? '', 200);
        $topic = safeText($_GET['topic'] ?? '', 500);
        $limit = max(1, min(100, (int)($_GET['limit'] ?? 100)));

        $papers = rgFetchPapers($pdo, $search, [], $limit);
        $out = [];
        foreach ($papers as $paper) {
            $relevance = rgRelevanceScore($topic, $paper);
            $out[] = rgRenderPaperSummary($paper, $relevance);
        }

        jsonResponse([
            'success' => true,
            'papers' => $out,
            'count' => count($out),
            'selector_mode' => true,
            'topic_used_for_scoring' => $topic !== '',
        ]);
    } catch (Throwable $e) {
        error_log('Research gap paper lookup failed: ' . $e->getMessage());
        jsonResponse([
            'success' => false,
            'code' => 'RESEARCH_GAP_PAPER_LOOKUP_FAILED',
            'message' => 'Research papers could not be loaded. Please verify that the research_papers table is available.'
        ], 500);
    }
}

requireCsrf();
$data = requestData();
$mode = safeText($data['mode'] ?? 'gaps', 40);

if ($mode === 'research_blueprint') {
    $topic = safeText($data['topic'] ?? '', 500);
    $rawGap = $data['gap'] ?? null;
    if ($topic === '') jsonResponse(['success' => false, 'code' => 'RESEARCH_BLUEPRINT_TOPIC_REQUIRED', 'message' => 'Research topic is required.'], 422);
    if (!is_array($rawGap)) jsonResponse(['success' => false, 'code' => 'RESEARCH_BLUEPRINT_GAP_REQUIRED', 'message' => 'A validated research gap is required.'], 422);

    $requestId = rgRequestId();
    $diagnostics = [
        'request_id' => $requestId,
        'stage' => 'blueprint_validation',
        'gemini_calls' => 0,
        'gemini_attempts' => 0,
        'last_http_status' => 0,
        'last_provider_code' => '',
        'last_model' => '',
        'failures' => [],
    ];

    try {
        $pdo = db();
        $rawEvidenceItems = is_array($rawGap['evidence_items'] ?? null) ? $rawGap['evidence_items'] : [];
        $paperIds = [];
        foreach ($rawEvidenceItems as $item) {
            if (is_array($item) && (int)($item['paper_id'] ?? 0) > 0) $paperIds[] = (int)$item['paper_id'];
        }
        foreach ((array)($rawGap['source_paper_ids'] ?? []) as $id) {
            if ((int)$id > 0) $paperIds[] = (int)$id;
        }
        $paperIds = array_values(array_unique(array_filter($paperIds, static fn(int $id): bool => $id > 0)));
        if (!$paperIds) jsonResponse(['success' => false, 'code' => 'RESEARCH_BLUEPRINT_NO_SOURCES', 'message' => 'The selected gap has no validated source papers. Please generate the research gaps again.'], 422);

        $sourceRows = rgFetchPapers($pdo, '', $paperIds, 20);
        $papersById = [];
        foreach ($sourceRows as $paper) $papersById[(int)$paper['id']] = $paper;

        // Re-validate the client-supplied gap against the real database evidence.
        // This prevents a modified browser payload from becoming the blueprint basis.
        $validatedGap = rgNormalizeGap($rawGap, $papersById);
        if ($validatedGap === null) {
            jsonResponse([
                'success' => false,
                'code' => 'RESEARCH_BLUEPRINT_GAP_NOT_VALIDATED',
                'message' => 'The selected gap could not be re-validated against the source paper evidence. Please generate the gaps again.'
            ], 422);
        }

        $diagnostics['stage'] = 'blueprint_generation';
        $result = rgGeminiJson(
            rgBuildResearchBlueprintPrompt($topic, $validatedGap, $papersById),
            $diagnostics,
            'research_blueprint'
        );

        $blueprint = is_array($result) ? rgNormalizeBlueprint($result, $validatedGap['source_paper_ids']) : null;
        if ($blueprint === null) {
            $diagnostics['stage'] = 'blueprint_generation_failed';
            jsonResponse([
                'success' => false,
                'code' => 'RESEARCH_BLUEPRINT_GEMINI_FAILED',
                'message' => 'The AI could not generate a complete research blueprint right now. Please retry.',
                'diagnostics' => $diagnostics
            ], 502);
        }

        try {
            $history = $pdo->prepare('INSERT INTO research_history(user_id,action_type,reference_id,title,metadata) VALUES(:uid,:a,NULL,:t,:m)');
            $history->execute([
                ':uid' => $userId,
                ':a' => 'research_gap_blueprint',
                ':t' => mb_substr($validatedGap['title'], 0, 500),
                ':m' => json_encode([
                    'topic' => $topic,
                    'gap_title' => $validatedGap['title'],
                    'source_paper_ids' => $validatedGap['source_paper_ids'],
                    'blueprint' => $blueprint,
                    'diagnostics' => $diagnostics,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable $historyError) {
            error_log('Research blueprint history failed: ' . $historyError->getMessage());
        }

        audit($pdo, $userId, 'research_gap_blueprint_generated');

        $sourcePapers = [];
        foreach ($validatedGap['source_paper_ids'] as $id) {
            if (isset($papersById[$id])) {
                $sourcePapers[] = rgRenderPaperSummary($papersById[$id], ['score' => 0, 'reasons' => []]);
            }
        }

        jsonResponse([
            'success' => true,
            'mode' => 'research_blueprint',
            'topic' => $topic,
            'gap' => $validatedGap,
            'blueprint' => $blueprint,
            'source_papers' => $sourcePapers,
            'diagnostics' => $diagnostics,
        ]);
    } catch (Throwable $e) {
        $diagnostics['stage'] = 'blueprint_unexpected_failure';
        $diagnostics['fatal_error_type'] = rgClassifyGeminiError($e);
        error_log(sprintf(
            'Research blueprint fatal failure request=%s stage=%s type=%s error=%s',
            (string)$diagnostics['request_id'],
            (string)$diagnostics['stage'],
            (string)$diagnostics['fatal_error_type'],
            $e->getMessage()
        ));
        jsonResponse([
            'success' => false,
            'code' => 'RESEARCH_BLUEPRINT_UNEXPECTED_ERROR',
            'message' => 'Research blueprint generation could not be completed. Please retry.',
            'diagnostics' => $diagnostics,
        ], 502);
    }
}

$topic = safeText($data['topic'] ?? '', 500);
$requestedIds = $data['paper_ids'] ?? [];

if ($topic === '') {
    jsonResponse(['success' => false, 'message' => 'Research topic is required.'], 422);
}

if (!is_array($requestedIds)) $requestedIds = [];
$requestedIds = array_values(array_unique(array_filter(array_map('intval', $requestedIds), static fn(int $id): bool => $id > 0)));

$requestId = rgRequestId();

/*
 * Phase 1C subscription entitlement.
 * The backend is authoritative: the browser cannot bypass the paper limit.
 * This check happens before any Gemini analysis.
 */
$pdo = db();
$subscriptionEntitlement = subscriptionCheckPaperLimit($pdo, $userId, count($requestedIds));

if (!$subscriptionEntitlement['allowed']) {
    audit($pdo, $userId, 'research_gap_subscription_limit_reached');

    $limitLabel = $subscriptionEntitlement['unlimited']
        ? 'unlimited'
        : (string)$subscriptionEntitlement['limit'];

    jsonResponse([
        'success' => false,
        'code' => 'SUBSCRIPTION_LIMIT_REACHED',
        'message' => sprintf(
            '%s plan allows up to %s selected papers for Research Gap analysis.',
            (string)$subscriptionEntitlement['plan']['name'],
            $limitLabel
        ),
        'entitlement' => $subscriptionEntitlement,
        'upgrade_options' => $subscriptionEntitlement['upgrade_options'] ?? [],
    ], 403);
}
$batchSize = rgBatchSize();
$diagnostics = [
    'request_id' => $requestId,
    'stage' => 'initializing',
    'batch_size' => $batchSize,
    'batches_total' => 0,
    'batches_succeeded' => 0,
    'batches_failed' => 0,
    'gemini_calls' => 0,
    'gemini_attempts' => 0,
    'last_http_status' => 0,
    'last_provider_code' => '',
    'last_model' => '',
    'failures' => [],
    'synthesis_attempted' => false,
    'synthesis_succeeded' => false,
];

try {
    $diagnostics['stage'] = 'paper_selection';

    // Explicitly selected papers must all be fetched. This is important for
    // Basic(20), Pro(40), and Unlimited plans.
    $selectionFetchLimit = $requestedIds
        ? count($requestedIds)
        : ($subscriptionEntitlement['unlimited']
            ? 1000000
            : max(20, (int)$subscriptionEntitlement['limit']));

    $candidates = rgFetchPapers($pdo, '', $requestedIds, $selectionFetchLimit);

    if (!$requestedIds) {
        // Topic discovery is still bounded by the selector's available search
        // window; the subscription limit controls how many relevant papers
        // enter the AI pipeline below.
        $candidates = rgFetchPapers($pdo, $topic, [], min(100, $selectionFetchLimit));
    }

    $scored = [];
    foreach ($candidates as $paper) {
        $scored[] = ['paper' => $paper, 'relevance' => rgRelevanceScore($topic, $paper)];
    }
    usort($scored, static function (array $a, array $b): int {
        $scoreCompare = $b['relevance']['score'] <=> $a['relevance']['score'];
        if ($scoreCompare !== 0) return $scoreCompare;
        return ((int)($b['paper']['publication_year'] ?? 0)) <=> ((int)($a['paper']['publication_year'] ?? 0));
    });

    // Subscription entitlement is the source of truth for how many relevant
    // papers can enter the AI analysis pipeline.
    $relevant = [];
    foreach ($scored as $entry) {
        if ($entry['relevance']['score'] >= 2) {
            $relevant[] = $entry;
        }

        if (
            !$subscriptionEntitlement['unlimited']
            && count($relevant) >= (int)$subscriptionEntitlement['limit']
        ) {
            break;
        }
    }

    if (!$relevant) {
        jsonResponse([
            'success' => true,
            'topic' => $topic,
            'summary' => 'No selected or discovered paper met the minimum topic-relevance threshold. No research gap was generated because the evidence set was not sufficiently relevant.',
            'gaps' => [],
            'recommended_direction' => '',
            'research_question' => '',
            'papers' => [],
            'excluded_papers' => array_map(static fn(array $entry): array => rgRenderPaperSummary($entry['paper'], $entry['relevance']), $scored),
            'evidence_mode' => $requestedIds ? 'selected_papers' : 'topic_search',
            'validation' => ['performed' => true, 'validated_gaps' => 0, 'rejected_gaps' => 0, 'validated_quotes' => 0, 'cross_paper_gaps' => 0, 'issues' => ['No relevant papers passed the minimum relevance threshold.']],
            'entitlement' => $subscriptionEntitlement,
            'diagnostics' => array_merge($diagnostics, ['stage' => 'relevance_filter']),
        ]);
    }

    $relevantPapers = array_map(static fn(array $entry): array => $entry['paper'], $relevant);
    $papersById = [];
    foreach ($relevantPapers as $paper) $papersById[(int)$paper['id']] = $paper;

    // -----------------------------
    // BATCHED GEMINI ANALYSIS
    // -----------------------------
    $diagnostics['stage'] = 'batch_analysis';
    $paperBatches = rgChunkPapers($relevantPapers, $batchSize);
    $diagnostics['batches_total'] = count($paperBatches);
    $batchCandidates = [];
    $batchIssues = [];

    foreach ($paperBatches as $batchIndex => $batchPapers) {
        $batchNumber = $batchIndex + 1;
        $batchIds = array_map(static fn(array $p): int => (int)$p['id'], $batchPapers);
        $batchPaperMap = array_intersect_key($papersById, array_flip($batchIds));
        $result = rgGeminiJson(
            rgBuildBatchPrompt($topic, rgBuildEvidence($batchPapers), $batchNumber, count($paperBatches)),
            $diagnostics,
            'batch_analysis',
            $batchNumber
        );

        if ($result === null) {
            $diagnostics['batches_failed']++;
            $batchIssues[] = "Batch {$batchNumber} could not be analyzed.";
            continue;
        }
        $diagnostics['batches_succeeded']++;

        $validatedBatchGaps = [];
        $rawBatchGaps = is_array($result['gaps'] ?? null) ? $result['gaps'] : [];
        foreach ($rawBatchGaps as $rawGap) {
            if (!is_array($rawGap)) continue;
            $normalized = rgNormalizeGap($rawGap, $batchPaperMap);
            if ($normalized !== null) $validatedBatchGaps[] = $normalized;
        }

        if ($validatedBatchGaps) {
            $batchCandidates[] = [
                'batch' => $batchNumber,
                'summary' => safeText($result['summary'] ?? '', 1600),
                'gaps' => $validatedBatchGaps,
                'recommended_direction' => safeText($result['recommended_direction'] ?? '', 1000),
                'research_question' => safeText($result['research_question'] ?? '', 800),
            ];
        } else {
            $batchIssues[] = "Batch {$batchNumber} returned no evidence-supported gaps.";
        }
    }

    if (!$batchCandidates) {
        $diagnostics['stage'] = 'batch_analysis_failed';
        $diagnostics['last_error_type'] = $diagnostics['failures'][0]['type'] ?? 'no_valid_gaps';
        $failedByProvider = $diagnostics['batches_failed'] > 0;
        jsonResponse([
            'success' => false,
            'code' => $failedByProvider ? 'RESEARCH_GAP_GEMINI_FAILED' : 'RESEARCH_GAP_NO_VALID_GAPS',
            'message' => $failedByProvider
                ? 'Research gap analysis could not be completed because the AI evidence batches failed. Please retry.'
                : 'Research gap analysis did not produce any evidence-supported gaps. Please try with more relevant papers.',
            'diagnostics' => $diagnostics,
        ], $failedByProvider ? 502 : 422);
    }

    $allBatchGaps = [];
    foreach ($batchCandidates as $batch) foreach ($batch['gaps'] as $gap) $allBatchGaps[] = $gap;

    // -----------------------------
    // CROSS-BATCH SYNTHESIS
    // -----------------------------
    $finalResult = null;
    if (count($batchCandidates) > 1) {
        $diagnostics['stage'] = 'cross_batch_synthesis';
        $diagnostics['synthesis_attempted'] = true;
        $finalResult = rgGeminiJson(
            rgBuildSynthesisPrompt($topic, $allBatchGaps, $relevantPapers),
            $diagnostics,
            'cross_batch_synthesis'
        );
        $diagnostics['synthesis_succeeded'] = is_array($finalResult);
    }

    $validatedGaps = [];
    $rejectedGaps = 0;
    $validationIssues = $batchIssues;

    if (is_array($finalResult)) {
        $rawGaps = is_array($finalResult['gaps'] ?? null) ? $finalResult['gaps'] : [];
        foreach ($rawGaps as $rawGap) {
            if (!is_array($rawGap)) {
                $rejectedGaps++;
                continue;
            }
            $normalized = rgNormalizeGap($rawGap, $papersById);
            if ($normalized === null) {
                $rejectedGaps++;
                $validationIssues[] = 'A synthesized gap was rejected because its evidence could not be fully traced to the supplied source text.';
                continue;
            }
            $validatedGaps[] = $normalized;
        }
    } else {
        // Synthesis failure does not discard already quote-validated evidence.
        $validatedGaps = $allBatchGaps;
        if ($diagnostics['synthesis_attempted']) $validationIssues[] = 'Cross-batch synthesis was unavailable; validated batch-level gaps were returned.';
    }

    $seenTitles = [];
    $deduped = [];
    foreach ($validatedGaps as $gap) {
        $key = rgNormalizeText($gap['title']);
        if ($key === '' || isset($seenTitles[$key])) continue;
        $seenTitles[$key] = true;
        $deduped[] = $gap;
    }
    $validatedGaps = array_slice($deduped, 0, 7);

    if (!$validatedGaps) {
        jsonResponse([
            'success' => false,
            'code' => 'RESEARCH_GAP_NO_VALIDATED_GAPS',
            'message' => 'The AI response did not contain any gaps that could be verified against the supplied paper evidence.',
            'diagnostics' => $diagnostics,
            'validation' => [
                'performed' => true,
                'validated_gaps' => 0,
                'rejected_gaps' => $rejectedGaps,
                'validated_quotes' => 0,
                'cross_paper_gaps' => 0,
                'issues' => $validationIssues,
            ],
        ], 422);
    }

    $finalSummary = is_array($finalResult) ? safeText($finalResult['summary'] ?? '', 2200) : '';
    $finalDirection = is_array($finalResult) ? safeText($finalResult['recommended_direction'] ?? '', 1400) : '';
    $finalQuestion = is_array($finalResult) ? safeText($finalResult['research_question'] ?? '', 1000) : '';
    if ($finalSummary === '' && isset($batchCandidates[0])) $finalSummary = $batchCandidates[0]['summary'];
    if ($finalDirection === '' && isset($batchCandidates[0])) $finalDirection = $batchCandidates[0]['recommended_direction'];
    if ($finalQuestion === '' && isset($batchCandidates[0])) $finalQuestion = $batchCandidates[0]['research_question'];

    $historyMetadata = [
        'topic' => $topic,
        'requested_paper_ids' => $requestedIds,
        'relevant_paper_ids' => array_map('intval', array_column($relevantPapers, 'id')),
        'gap_count' => count($validatedGaps),
        'selected_paper_count' => count($requestedIds),
        'subscription_plan' => $subscriptionEntitlement['plan']['code'] ?? null,
        'subscription_limit' => $subscriptionEntitlement['unlimited']
            ? null
            : ($subscriptionEntitlement['limit'] ?? null),
        'rejected_gaps' => $rejectedGaps,
        'validated_quotes' => array_sum(array_map(static fn(array $g): int => count($g['evidence_items']), $validatedGaps)),
        'diagnostics' => $diagnostics,
    ];

    try {
        $history = $pdo->prepare('INSERT INTO research_history(user_id,action_type,reference_id,title,metadata) VALUES(:uid,:a,NULL,:t,:m)');
        $history->execute([
            ':uid' => $userId,
            ':a' => 'research_gap_analysis',
            ':t' => mb_substr($topic, 0, 500),
            ':m' => json_encode($historyMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    } catch (Throwable $historyError) {
        error_log('Research gap history failed: ' . $historyError->getMessage());
    }

    audit($pdo, $userId, 'research_gap_generated');

    $paperSummaries = [];
    foreach ($relevant as $entry) {
        $paperSummaries[] = rgRenderPaperSummary($entry['paper'], $entry['relevance']);
    }

    jsonResponse([
        'success' => true,
        'topic' => $topic,
        'summary' => $finalSummary,
        'gaps' => $validatedGaps,
        'recommended_direction' => $finalDirection,
        'research_question' => $finalQuestion,
        'papers' => $paperSummaries,
        'excluded_papers' => array_map(static fn(array $entry): array => rgRenderPaperSummary($entry['paper'], $entry['relevance']), array_values(array_filter($scored, static fn(array $entry): bool => $entry['relevance']['score'] < 2))),
        'evidence_mode' => $requestedIds ? 'selected_papers' : 'topic_search',
        'entitlement' => $subscriptionEntitlement,
        'validation' => [
            'performed' => true,
            'validated_gaps' => count($validatedGaps),
            'rejected_gaps' => $rejectedGaps,
            'validated_quotes' => array_sum(array_map(static fn(array $g): int => count($g['evidence_items']), $validatedGaps)),
            'cross_paper_gaps' => count(array_filter($validatedGaps, static fn(array $g): bool => $g['gap_basis'] === 'cross_paper_pattern')),
            'issues' => $validationIssues,
        ],
    ]);
} catch (Throwable $e) {
    $diagnostics['stage'] = 'unexpected_failure';
    $diagnostics['fatal_error_type'] = rgClassifyGeminiError($e);
    error_log(sprintf(
        'Research gap fatal failure request=%s stage=%s type=%s error=%s',
        (string)($diagnostics['request_id'] ?? 'unknown'),
        (string)($diagnostics['stage'] ?? 'unknown'),
        (string)($diagnostics['fatal_error_type'] ?? 'unknown'),
        $e->getMessage()
    ));
    jsonResponse([
        'success' => false,
        'code' => 'RESEARCH_GAP_UNEXPECTED_ERROR',
        'message' => 'Research gap analysis could not be completed. Please retry. If the problem continues, share the diagnostic reference with the administrator.',
        'diagnostics' => $diagnostics,
    ], 502);
}
