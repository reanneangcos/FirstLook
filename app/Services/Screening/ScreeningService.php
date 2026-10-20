<?php

namespace App\Services\Screening;

use App\Models\ScreeningSession;
use App\Services\OpenAI\OpenAIClient;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScreeningService
{
    public const RETRY_FEEDBACK_VERSION = 'validation-feedback-v1';

    public function __construct(private OpenAIClient $client, private ScreeningOutput $output, private RuleLayer $rules) {}

    public function screen(array $data, ?int $userId, ?int $patientVisitId = null, bool $evaluateRules = true, array $evaluationMetadata = [], bool $studyCriteria = false): ScreeningSession
    {
        if ($studyCriteria) {
            app(StudyProtocol::class)->assertReady();
        }
        $patient = PatientFields::only($data['patient']);
        $promptVersion = $studyCriteria ? StudyProtocol::PROMPT
            : config($patientVisitId === null ? 'triage.prompt_version' : 'triage.patient_prompt_version');
        $preferredLanguage = $evaluationMetadata['response_language'] ?? $data['language'];
        $responseLanguage = $patientVisitId === null ? null
            : (array_key_exists($preferredLanguage, PatientChatContent::LOCALES) ? $preferredLanguage : 'English');
        $prompt = $studyCriteria ? app(StudyProtocol::class)->prompt()
            : file_get_contents(resource_path('prompts/'.$promptVersion.'.txt'));
        $prompt = str_replace('{{normalization_instructions}}', (new NormalizedPatientFacts)->instructions(), $prompt);
        $prompt = str_replace('{{response_language}}', $responseLanguage ?? 'English', $prompt);
        if ($responseLanguage !== null) {
            $prompt .= "\n\n".PatientChatContent::voiceGuidance($responseLanguage, forScreening: true);
        }
        $settings = [
            'model' => config('triage.model'), 'store' => false,
            'reasoning' => ['effort' => config('triage.reasoning_effort')],
            'max_output_tokens' => config('triage.max_output_tokens'),
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'screening_result', 'strict' => true, 'schema' => $this->output->schema()]],
        ];
        $session = ScreeningSession::create([
            'user_id' => $userId,
            'patient_visit_id' => $patientVisitId,
            'dataset_case_id' => ($data['dataset_case_id'] ?? '') === '' ? null : $data['dataset_case_id'],
            'variant_id' => ($data['variant_id'] ?? '') === '' ? null : $data['variant_id'],
            'language' => $data['language'],
            'original_input' => Arr::only($data['patient'], PatientFields::NAMES),
            'patient_input' => $patient,
            'requested_model' => (string) config('triage.model'),
            'prompt_version' => $promptVersion, 'prompt_text' => $prompt,
            'method_b_status' => 'not_evaluated',
            'request_settings' => $settings + [
                'evaluation_method' => $evaluateRules ? 'paired' : 'method_a_only',
                'evaluation' => $evaluationMetadata,
                'schema_version' => config('triage.schema_version'),
                'validation_version' => ScreeningOutput::VALIDATION_VERSION,
                'normalization_version' => NormalizedPatientFacts::VERSION,
                'retry_feedback_version' => self::RETRY_FEEDBACK_VERSION,
                'classification_mode' => $studyCriteria ? 'reviewed_study_criteria' : config('triage.classification_mode'),
                'criteria_status' => $studyCriteria ? 'reviewed_definitions' : 'pending_clinical_review',
                'response_language' => $responseLanguage,
                'patient_voice_version' => $responseLanguage === null ? null : config('triage.patient_voice_version'),
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
        /** Dataset metadata stays local. Only patient chat sets a response-language preference. */
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
            $feedback = null;
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
                        $feedback = $this->validationFeedback($error);
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

                        if ($evaluateRules) {
                            $this->saveRuleResult($session, $parsed['extracted_facts'], $parsed['normalized_facts']);
                        }

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
            if ($feedback !== null) {
                $payload['input'][2] = ['role' => 'system', 'content' => $feedback];
            }
            if (isset($payload['input'][2])) {
                $requestSettings = $session->request_settings;
                $requestSettings['retry_feedback'][$number + 1] = $payload['input'][2]['content'];
                $session->update(['request_settings' => $requestSettings]);
            }
            usleep(min(2000, max(0, config('triage.retry_delay_ms') * $number)) * 1000);
        }

        return $session;
    }

    private function validationFeedback(InvalidModelOutput $error): string
    {
        $field = in_array($error->field, PatientFields::NAMES, true) ? ' Field: '.$error->field.'.' : '';
        $instruction = match ($error->failureCode) {
            'unknown_reported_as_known' => 'An unreported input was treated as a known fact. Use state unknown with null value and evidence for missing inputs and reporting placeholders.',
            'ungrounded_fact' => 'Evidence did not exactly match its source field. Copy the original characters verbatim; do not translate, spell-correct, or normalize dialect words. Value and evidence must be identical.',
            'ungrounded_normalized_fact' => 'A normalized concept lacks a verbatim evidence anchor in extracted_facts. Keep the original source quote and field. Normalize only the concept identifier, never its evidence. Do not use tests or devices as symptom evidence.',
            'invalid_normalized_facts' => 'Use the allowed normalized concept identifiers and states, once per concept. Include evidence for known facts; use unknown for ambiguity. Do not add ESI labels, diagnoses, high-risk judgments or resource counts.',
            default => 'The response did not satisfy the output schema. Return one complete JSON object with the required fields and valid types.',
        };

        return 'Output validation failed.'.$field.' '.$instruction
            .' Re-read the original patient record and return the complete response. This feedback supplies no clinical facts or expected priority. Follow the original classification instructions.';
    }

    private function elapsed(int $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }

    /** @param list<array{field: string, state: string, value: ?string, evidence: ?string}> $facts */
    private function saveRuleResult(ScreeningSession $session, array $facts, array $normalizedFacts): void
    {
        /** The patient workflow has no approved clinical-assessment or measured-vitals input. */
        $session->update(['method_b_input' => ['extracted_facts' => $facts, 'normalized_facts' => $normalizedFacts, 'clinical_assessment' => []],
            'method_b_rule_version' => $this->rules->version()]);
        try {
            $result = $this->rules->evaluate($facts, [], $normalizedFacts);
            $session->update(['method_b_status' => $result['status'], 'method_b_priority' => $result['priority'],
                'method_b_rule_version' => $result['rule_version'], 'method_b_result' => $result]);
        } catch (Throwable $error) {
            Log::error('Method B evaluation failed.', ['screening_id' => $session->id, 'exception_type' => $error::class]);
            $session->update(['method_b_status' => 'technical_failure', 'method_b_priority' => null,
                'method_b_result' => ['status' => 'technical_failure', 'priority' => null,
                    'rule_version' => $session->method_b_rule_version, 'matched_rule_ids' => [], 'trace' => [],
                    'flags' => [], 'stopped_at' => null,
                    'explanation' => 'The rule evaluation could not be completed. Method A remains saved unchanged.']]);
        }
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
