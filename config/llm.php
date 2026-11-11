<?php

return [
    // Allowed: none | ollama | openai | anthropic
    'driver' => env('LLM_DRIVER', 'none'),

    // Optional global override; if unset, each driver uses its configured default model.
    'model' => env('LLM_MODEL'),

    // Request HTTP timeout (seconds). Background ai queue job timeout is 180s.
    'timeout' => (int) env('LLM_TIMEOUT', 60),

    // Max generated output tokens per explanation call.
    'max_tokens' => (int) env('LLM_MAX_TOKENS', 600),

    // Hard input prompt character limit checked BEFORE any HTTP call.
    'max_input_chars' => (int) env('LLM_MAX_INPUT_CHARS', 12000),

    // Per-tenant monthly token budget (input_tokens + output_tokens).
    'monthly_token_budget' => (int) env('LLM_MONTHLY_TOKEN_BUDGET', 100000),

    'drivers' => [
        'ollama' => [
            'base_url' => rtrim((string) env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'), '/'),
            'model' => env('OLLAMA_MODEL', 'llama3.2:3b'),
        ],
        'openai' => [
            'base_url' => rtrim((string) env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
        'anthropic' => [
            'base_url' => rtrim((string) env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), '/'),
            'api_key' => env('ANTHROPIC_API_KEY'),
            'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
            'model' => env('ANTHROPIC_MODEL', 'claude-3-5-haiku-latest'),
        ],
    ],
];
