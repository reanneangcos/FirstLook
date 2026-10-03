<?php

namespace App\Services\Screening;

use App\Models\ScreeningSession;
use App\Services\OpenAI\OpenAIClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ScreeningService
{
    public function __construct(private OpenAIClient $client, private ScreeningOutput $output) {}

    public function screen(array $data, int $userId): ScreeningSession
    {
        $patient = PatientFields::only($data['patient']);
        $prompt = file_get_contents(resource_path('prompts/'.config('triage.prompt_version').'.txt'));
        $settings = [
            'model' => config('triage.model'), 'store' => false,
            'reasoning' => ['effort' => config('triage.reasoning_effort')],
            'max_output_tokens' => config('triage.max_output_tokens'),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'screening_result', 'strict' => true, 'schema' => $this->output->schema()]],
        ];
        $session = ScreeningSession::create([
            'user_id' => $userId,
            'dataset_case_id' => ($data['dataset_case_id'] ?? '') === '' ? null : $data['dataset_case_id'],
            'variant_id' => ($data['variant_id'] ?? '') === '' ? null : $data['variant_id'],
            'language' => $data['language'],
            'original_input' => Arr::only($data['patient'], PatientFields::NAMES),
            'patient_input' => $patient,
            'requested_model' => (string) config('triage.model'),
            'prompt_version' => config('triage.prompt_version'), 'prompt_text' => $prompt,
            'request_settings' => $settings + [
                'schema_version' => config('triage.schema_version'),
                'timeout_seconds' => config('triage.timeout_seconds'),
                'connect_timeout_seconds' => config('triage.connect_timeout_seconds'),
                'max_attempts' => min(3, max(1, config('triage.max_attempts'))),
                'retry_delay_ms' => config('triage.retry_delay_ms'),
                'temperature' => 'omitted; model default', 'top_p' => 'omitted; model default',
            ],
        ]);
        if (! config('triage.api_key') || ! config('triage.model')) {
            return $this->fail($session, 'configuration_missing', 'Set OPENAI_API_KEY and OPENAI_MODEL in the server environment. No request was sent.', 0);
        }
        // Dataset identifiers, language labels and answer-key metadata never enter the prompt.
        $payload = $settings + ['input' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => json_encode($patient, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
        ]];
        $started = hrtime(true);
        $attempts = $session->request_settings['max_attempts'];
        for ($number = 1; $number <= $attempts; $number++) {
            $attemptStarted = now();
            $clock = hrtime(true);
            $raw = null;
            $parsed = null;
            $httpStatus = null;
            $code = null;
            $retryable = true;
            try {
                $response = $this->client->send($payload);
                $httpStatus = $response->status();
                if ($response->successful()) {
                    // Save successful response bytes before parsing. Never rewrite a prediction.
                    $raw = $response->body();
                    $attempt = $session->attempts()->create([
                        'number' => $number, 'status' => 'received', 'http_status' => $httpStatus,
                        'original_response' => $raw, 'latency_ms' => $this->elapsed($clock),
                        'started_at' => $attemptStarted, 'completed_at' => now(),
                    ]);
                    try {
                        $parsed = $this->output->parse($raw, $patient);
                    } catch (InvalidModelOutput $error) {
                        $code = $error->failureCode;
                        $retryable = $code !== 'model_refusal';
                    }
                    $attempt->update(['status' => $parsed ? 'accepted' : 'failed', 'parsed_output' => $parsed, 'failure_code' => $code]);
                    if ($parsed !== null) {
                        $envelope = $response->json();
                        DB::transaction(function () use ($session, $parsed, $raw, $envelope, $started): void {
                            $session->update([
                                'original_response' => $raw, 'parsed_output' => $parsed,
                                'method_a_status' => $parsed['status'], 'method_a_priority' => $parsed['priority'],
                                'processing_status' => 'completed', 'returned_model' => $envelope['model'],
                                'token_usage' => $envelope['usage'] ?? null,
                                'latency_ms' => $this->elapsed($started), 'completed_at' => now(),
                            ]);
                        });

                        return $session->fresh();
                    }
                } else {
                    $code = match ($httpStatus) {
                        401, 403 => 'credentials_rejected', 429 => 'rate_limited',
                        default => $httpStatus >= 500 ? 'provider_unavailable' : 'request_rejected',
                    };
                    $retryable = $httpStatus === 429 || $httpStatus >= 500;
                }
            } catch (ConnectionException) {
                // Never persist exception messages: they can contain request details.
                $code = 'connection_timeout';
            }
            if ($raw === null) {
                $session->attempts()->create([
                    'number' => $number, 'status' => 'failed', 'http_status' => $httpStatus,
                    'failure_code' => $code, 'latency_ms' => $this->elapsed($clock),
                    'started_at' => $attemptStarted, 'completed_at' => now(),
                ]);
            }
            if (! $retryable || $number === $attempts) {
                return $this->fail($session, $code, $this->failureMessage($code), $this->elapsed($started));
            }
            usleep(min(2000, max(0, config('triage.retry_delay_ms') * $number)) * 1000);
        }

        return $session;
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }

    private function fail(ScreeningSession $session, string $code, string $message, int $latency): ScreeningSession
    {
        $session->update(['processing_status' => 'technical_failure', 'failure_code' => $code,
            'failure_message' => $message, 'latency_ms' => $latency, 'completed_at' => now()]);

        return $session->fresh();
    }

    private function failureMessage(string $code): string
    {
        return match ($code) {
            'rate_limited' => 'The provider rate limit was reached. The bounded request sequence has ended.',
            'connection_timeout' => 'The provider connection failed or timed out. No prediction was accepted.',
            'credentials_rejected' => 'The provider rejected the credentials or model access. Check the server configuration.',
            'provider_unavailable' => 'The provider was unavailable after the bounded request sequence.',
            'request_rejected' => 'The provider rejected the request. Check the model and supported request settings.',
            'model_refusal' => 'The model refused this request. No preliminary priority was assigned.',
            default => 'The model output was malformed, incomplete or failed validation. Inspect the saved attempts.',
        };
    }
}
