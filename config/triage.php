<?php

return [
    'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
    'api_key' => env('OPENAI_API_KEY'),
    'prompt_version' => 'screening-v0.3-llm-baseline',
    'patient_prompt_version' => 'screening-v0.3-llm-baseline',
    'classification_mode' => 'exploratory_llm_only',
    'intake_version' => 'intake-v1-conversational',
    'intake_max_output_tokens' => 2500,
    'schema_version' => 'screening-v1',
    'reasoning_effort' => 'low',
    'max_output_tokens' => 4000,
    'timeout_seconds' => 30,
    'connect_timeout_seconds' => 5,
    'max_attempts' => 3,
    'retry_delay_ms' => 500,
    'languages' => ['English', 'Bisaya', 'Tagalog'],
];
