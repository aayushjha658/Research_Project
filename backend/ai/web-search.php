<?php

declare(strict_types=1);

/*
 * Research AI - Tavily Web Search Endpoint
 *
 * Purpose:
 *   Secure backend-only web search endpoint for the Research AI assistant.
 *
 * Request:
 *   POST /backend/ai/web-search.php
 *   Content-Type: application/json
 *
 * JSON body:
 *   {
 *     "query": "latest AI research in dentistry",
 *     "search_depth": "basic",
 *     "max_results": 6,
 *     "topic": "general",
 *     "time_range": "month"
 *   }
 *
 * Security:
 *   - Authenticated users only
 *   - CSRF protection via X-CSRF-Token or csrf_token
 *   - Tavily API key never exposed to frontend
 *   - Query/result limits to reduce abuse and unnecessary credits
 */

require_once __DIR__ . '/../common/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = requireAuth();
methodOnly('POST');
requireCsrf();

/**
 * Return a clean JSON response and stop execution.
 */
function webSearchResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

/**
 * Read a string from an array and enforce a maximum length.
 */
function webSearchString(mixed $value, int $maxLength): string
{
    if (!is_string($value)) {
        return '';
    }

    $value = trim($value);

    if ($value === '') {
        return '';
    }

    return mb_substr($value, 0, $maxLength);
}

/**
 * Convert a possibly mixed Tavily field into a safe scalar string.
 */
function webSearchScalar(mixed $value, int $maxLength = 12000): string
{
    if (!is_scalar($value)) {
        return '';
    }

    return trim(mb_substr((string)$value, 0, $maxLength));
}

/**
 * Validate a URL before returning it to the frontend.
 */
function isSafeHttpUrl(string $url): bool
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));

    return $scheme === 'http' || $scheme === 'https';
}

function loadResearchAiEnv(): void
{
    $envFile = __DIR__ . '/../config/.env';

    if (!is_file($envFile) || !is_readable($envFile)) {
        return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');

        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));

        if ($name === '' || !preg_match('/^[A-Z][A-Z0-9_]*$/', $name)) {
            continue;
        }

        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
             ($value[0] === "'" && $value[strlen($value) - 1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        // Do not overwrite a real server environment variable.
        if (getenv($name) === false || getenv($name) === '') {
            putenv($name . '=' . $value);
        }
    }
}

loadResearchAiEnv();

try {
    $data = requestData();

    $query = webSearchString($data['query'] ?? '', 500);

    if ($query === '') {
        webSearchResponse([
            'success' => false,
            'message' => 'Search query is required.'
        ], 422);
    }

    if (mb_strlen($query) < 2) {
        webSearchResponse([
            'success' => false,
            'message' => 'Search query is too short.'
        ], 422);
    }

    $searchDepth = strtolower(
        webSearchString($data['search_depth'] ?? 'basic', 20)
    );

    if (!in_array($searchDepth, ['basic', 'advanced'], true)) {
        $searchDepth = 'basic';
    }

    $maxResults = filter_var(
        $data['max_results'] ?? 6,
        FILTER_VALIDATE_INT,
        [
            'options' => [
                'default' => 6,
                'min_range' => 1,
                'max_range' => 10
            ]
        ]
    );

    if ($maxResults === false) {
        $maxResults = 6;
    }

    $topic = strtolower(
        webSearchString($data['topic'] ?? 'general', 20)
    );

    if (!in_array($topic, ['general', 'news'], true)) {
        $topic = 'general';
    }

    $timeRange = strtolower(
        webSearchString($data['time_range'] ?? '', 20)
    );

    $allowedTimeRanges = [
        '',
        'day',
        'week',
        'month',
        'year'
    ];

    if (!in_array($timeRange, $allowedTimeRanges, true)) {
        $timeRange = '';
    }

    /*
     * Time range is only useful when explicitly requested.
     * It is passed to Tavily as-is after validation.
     */
    $includeDomains = [];
    if (isset($data['include_domains']) && is_array($data['include_domains'])) {
        foreach ($data['include_domains'] as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $domain = trim($domain);

            if ($domain === '' || mb_strlen($domain) > 120) {
                continue;
            }

            // Only accept host/domain-like values; URLs are not accepted here.
            if (!preg_match('/^[a-zA-Z0-9*.-]+$/', $domain)) {
                continue;
            }

            $includeDomains[] = $domain;

            if (count($includeDomains) >= 10) {
                break;
            }
        }
    }

    $apiKey = trim((string)(getenv('TAVILY_API_KEY') ?: ''));

    if ($apiKey === '') {
        webSearchResponse([
            'success' => false,
            'message' => 'Tavily API key is not configured on the server.'
        ], 500);
    }

    $payload = [
        'query' => $query,
        'search_depth' => $searchDepth,
        'max_results' => $maxResults,
        'topic' => $topic,
        'include_answer' => false,
        'include_raw_content' => false,
        'include_images' => false,
        'include_favicon' => true
    ];

    if ($timeRange !== '') {
        $payload['time_range'] = $timeRange;
    }

    if ($includeDomains !== []) {
        $payload['include_domains'] = array_values($includeDomains);
    }

    $jsonPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if ($jsonPayload === false) {
        throw new RuntimeException('Unable to encode Tavily request.');
    }

    $ch = curl_init('https://api.tavily.com/search');

    if ($ch === false) {
        throw new RuntimeException('Unable to initialize the web search client.');
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($rawResponse === false) {
        error_log('Tavily search cURL error: ' . $curlError);

        webSearchResponse([
            'success' => false,
            'message' => 'Web search service could not be reached right now.'
        ], 502);
    }

    $decoded = json_decode($rawResponse, true);

    if (!is_array($decoded)) {
        error_log('Tavily returned invalid JSON. HTTP status: ' . $httpStatus);

        webSearchResponse([
            'success' => false,
            'message' => 'Web search returned an invalid response.'
        ], 502);
    }

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $providerMessage = webSearchScalar(
            $decoded['detail']
                ?? $decoded['message']
                ?? $decoded['error']
                ?? '',
            500
        );

        if ($httpStatus === 401 || $httpStatus === 403) {
            $message = 'Tavily authentication failed. Check the server API key.';
        } elseif ($httpStatus === 429) {
            $message = 'Tavily search limit has been reached. Please try again later.';
        } else {
            $message = 'Tavily web search failed.';
        }

        error_log(
            'Tavily API error. HTTP ' . $httpStatus .
            ($providerMessage !== '' ? ' - ' . $providerMessage : '')
        );

        webSearchResponse([
            'success' => false,
            'message' => $message,
            'provider_status' => $httpStatus
        ], $httpStatus >= 400 && $httpStatus < 500 ? $httpStatus : 502);
    }

    $results = [];

    if (isset($decoded['results']) && is_array($decoded['results'])) {
        foreach ($decoded['results'] as $result) {
            if (!is_array($result)) {
                continue;
            }

            $url = webSearchScalar($result['url'] ?? '', 2000);

            if (!isSafeHttpUrl($url)) {
                continue;
            }

            $results[] = [
                'title' => webSearchScalar($result['title'] ?? '', 500),
                'url' => $url,
                'content' => webSearchScalar($result['content'] ?? '', 12000),
                'score' => isset($result['score']) && is_numeric($result['score'])
                    ? (float)$result['score']
                    : null,
                'published_date' => webSearchScalar($result['published_date'] ?? '', 100)
            ];

            if (count($results) >= $maxResults) {
                break;
            }
        }
    }

    if ($results === []) {
        webSearchResponse([
            'success' => true,
            'message' => 'No relevant web results were found.',
            'query' => $query,
            'search_depth' => $searchDepth,
            'topic' => $topic,
            'results' => [],
            'sources' => []
        ]);
    }

    $sources = [];

    foreach ($results as $result) {
        $sources[] = [
            'title' => $result['title'] !== ''
                ? $result['title']
                : $result['url'],
            'url' => $result['url'],
            'published_date' => $result['published_date']
        ];
    }

    // Optional audit entry. Failure must never break a successful search.
    try {
        audit(db(), $userId, 'web_search');
    } catch (Throwable $auditError) {
        error_log('Web search audit error: ' . $auditError->getMessage());
    }

    webSearchResponse([
        'success' => true,
        'message' => 'Web search completed successfully.',
        'query' => $query,
        'search_depth' => $searchDepth,
        'topic' => $topic,
        'result_count' => count($results),
        'results' => $results,
        'sources' => $sources
    ]);

} catch (Throwable $error) {
    error_log('Research AI Web Search Error: ' . $error->getMessage());

    webSearchResponse([
        'success' => false,
        'message' => 'Unable to perform web search right now.'
    ], 500);
}
