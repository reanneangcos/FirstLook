<?php

namespace App\Services\OpenAI;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAIClient
{
    public function send(array $payload): Response
    {
        // Retries belong to ScreeningService so every attempt is persisted.
        // Do not log this request, its Authorization header, or provider exceptions.
        return Http::withToken(config('triage.api_key'))
            ->acceptJson()
            ->connectTimeout(config('triage.connect_timeout_seconds'))
            ->timeout(config('triage.timeout_seconds'))
            ->withOptions(['allow_redirects' => false])
            ->post('https://api.openai.com/v1/responses', $payload);
    }
}
