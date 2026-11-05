<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';


/*
|--------------------------------------------------------------------------
| Gemini API Configuration
|--------------------------------------------------------------------------
*/

function geminiApiKey(): string
{
    $apiKey = getenv('GEMINI_API_KEY');

    if (!is_string($apiKey) || trim($apiKey) === '') {
        throw new RuntimeException(
            'Gemini API key is not configured.'
        );
    }

    return trim($apiKey);
}


function geminiModel(): string
{
    $model = getenv('GEMINI_MODEL');

    if (!is_string($model) || trim($model) === '') {
        return 'gemini-3.6-flash';
    }

    return trim($model);
}


/*
|--------------------------------------------------------------------------
| Call Gemini API
|--------------------------------------------------------------------------
*/

function callGemini(string $prompt): array
{
    $apiKey = geminiApiKey();
    $model  = geminiModel();


    /*
    |--------------------------------------------------------------------------
    | Gemini API URL
    |
    | IMPORTANT:
    | API key is NOT added to the URL.
    | It is sent using the x-goog-api-key header.
    |--------------------------------------------------------------------------
    */

    $url =
        'https://generativelanguage.googleapis.com/v1beta/models/' .
        rawurlencode($model) .
        ':generateContent';


    /*
    |--------------------------------------------------------------------------
    | Request Payload
    |--------------------------------------------------------------------------
    */

    $payload = [

        'contents' => [

            [

                'role' => 'user',

                'parts' => [

                    [
                        'text' => $prompt
                    ]

                ]

            ]

        ],

        'generationConfig' => [

            'temperature' => 0.3,

            'responseMimeType' => 'application/json'

        ]

    ];


    $jsonPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );


    if ($jsonPayload === false) {

        throw new RuntimeException(
            'Unable to prepare Gemini request.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Initialize cURL
    |--------------------------------------------------------------------------
    */

    $curl = curl_init();

    if ($curl === false) {

        throw new RuntimeException(
            'Unable to initialize Gemini connection.'
        );
    }


    curl_setopt_array(
        $curl,
        [

            CURLOPT_URL => $url,

            CURLOPT_POST => true,

            CURLOPT_POSTFIELDS => $jsonPayload,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_TIMEOUT => 90,

            CURLOPT_CONNECTTIMEOUT => 20,

            CURLOPT_HTTPHEADER => [

                'Content-Type: application/json',

                'x-goog-api-key: ' . $apiKey

            ]

        ]
    );


    /*
    |--------------------------------------------------------------------------
    | Execute Request
    |--------------------------------------------------------------------------
    */

    $response = curl_exec($curl);


    $httpCode = (int) curl_getinfo(
        $curl,
        CURLINFO_HTTP_CODE
    );


    $curlError = curl_error($curl);


    curl_close($curl);


    /*
    |--------------------------------------------------------------------------
    | Connection Error
    |--------------------------------------------------------------------------
    */

    if ($response === false) {

        throw new RuntimeException(
            'Gemini connection failed: ' .
            $curlError
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Decode Response
    |--------------------------------------------------------------------------
    */

    $data = json_decode(
        $response,
        true
    );


    if (!is_array($data)) {

        throw new RuntimeException(
            'Invalid response received from Gemini. HTTP Status: ' .
            $httpCode
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Handle Gemini API Errors
    |--------------------------------------------------------------------------
    */

    if ($httpCode < 200 || $httpCode >= 300) {

        $message =
            $data['error']['message']
            ?? 'Gemini API request failed.';


        $status =
            $data['error']['status']
            ?? 'UNKNOWN_ERROR';


        throw new RuntimeException(
            'Gemini API Error (' .
            $status .
            '): ' .
            $message
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Extract Generated Text
    |--------------------------------------------------------------------------
    */

    $text =
        $data['candidates'][0]['content']['parts'][0]['text']
        ?? null;


    if (!is_string($text) || trim($text) === '') {

        throw new RuntimeException(
            'Gemini did not return analysis content.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Decode AI JSON
    |--------------------------------------------------------------------------
    */

    $analysis = json_decode(
        $text,
        true
    );


    if (!is_array($analysis)) {

        throw new RuntimeException(
            'Gemini returned invalid analysis JSON.'
        );
    }


    return $analysis;
}