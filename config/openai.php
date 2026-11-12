<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API (report assistant)
    |--------------------------------------------------------------------------
    |
    | Configure these in Dokploy (or the runtime environment). Never commit
    | secret values into source control. ChatGPT Plus alone is not enough;
    | this needs an API key from platform.openai.com.
    |
    */

    'api_key' => env('OPENAI_API_KEY'),

    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),

    'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),

    'timeout' => (int) env('OPENAI_TIMEOUT', 30),

    'max_rows' => (int) env('OPENAI_REPORT_MAX_ROWS', 80),

];
