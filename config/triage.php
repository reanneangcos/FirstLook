<?php

return [
    'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
    'api_key' => env('OPENAI_API_KEY'),
    'prompt_version' => 'screening-v0.7-normalized-facts',
    'patient_prompt_version' => 'screening-v0.7-normalized-facts',
    'patient_voice_version' => 'patient-voice-v2-casual',
    'classification_mode' => 'exploratory_llm_only',
    'intake_version' => 'intake-v5-field-map',
    'intake_max_output_tokens' => 2500,
    'schema_version' => 'screening-v2-normalized',
    'reasoning_effort' => 'low',
    'max_output_tokens' => 4000,
    'timeout_seconds' => 30,
    'connect_timeout_seconds' => 5,
    'max_attempts' => 3,
    'retry_delay_ms' => 500,
    'languages' => ['English', 'Bisaya', 'Tagalog'],
];
