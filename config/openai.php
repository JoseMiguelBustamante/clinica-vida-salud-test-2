<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Report assistant LLM (OpenAI-compatible HTTP API)
    |--------------------------------------------------------------------------
    |
    | Set these only in Dokploy / runtime environment. Do not commit secrets.
    | Any OpenAI-compatible provider works when BASE_URL + MODEL + KEY match
    | that provider. There are no vendor defaults in source.
    |
    */

    'api_key' => env('OPENAI_API_KEY'),

    'model' => env('OPENAI_MODEL'),

    'base_url' => env('OPENAI_BASE_URL'),

    'timeout' => (int) env('OPENAI_TIMEOUT', 30),

    'max_rows' => (int) env('OPENAI_REPORT_MAX_ROWS', 80),

    // Optional OpenRouter attribution headers (no secrets).
    'http_referer' => env('OPENAI_HTTP_REFERER'),

    'app_title' => env('OPENAI_APP_TITLE', 'Clinica Vida Salud'),

];
