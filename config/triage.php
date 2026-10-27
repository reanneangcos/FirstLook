<?php

return [
    'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
    'api_key' => env('OPENAI_API_KEY'),
    'prompt_version' => 'screening-v0.8-reporting-states',
    'patient_prompt_version' => 'screening-v0.8-reporting-states',
    'patient_voice_version' => 'patient-voice-v2-casual',
    'classification_mode' => 'exploratory_llm_only',
    'intake_version' => 'intake-v6-reporting-states',
    'intake_max_output_tokens' => 2500,
    'schema_version' => 'screening-v2-normalized',
    'reasoning_effort' => 'low',
    'max_output_tokens' => 4000,
    'timeout_seconds' => 60,
    'connect_timeout_seconds' => 5,
    'max_attempts' => 3,
    'retry_delay_ms' => 500,
    'languages' => ['English', 'Bisaya', 'Tagalog'],
];
