<?php

namespace App\Console\Commands;

use App\Models\PatientVisit;
use App\Models\ScreeningSession;
use App\Services\Screening\PatientFields;
use App\Services\Screening\ScreeningOutput;
use App\Services\Screening\ScreeningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

#[Signature('study:dev-baseline {input : DEV-only JSON with patient fields and local identifiers} {--run-id= : Unique development run ID} {--seed=42} {--limit= : Maximum new records this invocation} {--dry-run : Validate and summarize without requests or database writes}')]
#[Description('Save a resumable, LLM-only development pass; never evaluate Method B')]
class RunDevelopmentBaseline extends Command
{
    public function handle(ScreeningService $service): int
    {
        $path = $this->argument('input');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Input file is not readable.');

            return self::FAILURE;
        }
        $raw = file_get_contents($path);
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Input must be valid JSON.');

            return self::FAILURE;
        }
        $rules = [
            'manifest' => ['required', 'array:split,source_sha256,cases'],
            'manifest.split' => ['required', Rule::in(['DEV'])],
            'manifest.source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'manifest.cases' => ['required', 'array', 'list', 'min:1'],
            'manifest.cases.*' => ['required', 'array:split,dataset_case_id,variant_id,language,patient'],
            'manifest.cases.*.split' => ['required', Rule::in(['DEV'])],
            'manifest.cases.*.dataset_case_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'manifest.cases.*.variant_id' => ['required', 'string', 'max:80', 'distinct', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'manifest.cases.*.language' => ['required', Rule::in(['English', 'Tagalog', 'Bisaya', 'English–Tagalog', 'English–Bisaya', 'Tagalog–Bisaya'])],
            'manifest.cases.*.patient' => ['required', 'array:'.implode(',', PatientFields::NAMES)],
            'run_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'seed' => ['required', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
        foreach (PatientFields::NAMES as $field) {
            $rules['manifest.cases.*.patient.'.$field] = $field === 'age'
                ? ['present', 'nullable', 'integer', 'min:18'] : ['present', 'nullable', 'string', 'max:3000'];
        }
        $validator = Validator::make(['manifest' => $manifest, 'run_id' => $this->option('run-id'),
            'seed' => $this->option('seed'), 'limit' => $this->option('limit')], $rules);
        if ($validator->fails()) {
            $this->error('Invalid development manifest/options: '.implode(', ', $validator->errors()->keys()));

            return self::FAILURE;
        }
        $cases = collect($manifest['cases'])->sortBy(fn (array $case): string => hash('sha256', $this->option('seed').':'.$case['variant_id']))->values();
        $this->info('DEV only; Method A only; '.$cases->count().' versions / '.$cases->pluck('dataset_case_id')->unique()->count().' canonical cases.');
        $this->line(json_encode($cases->countBy('language'), JSON_UNESCAPED_UNICODE));
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! config('triage.api_key') || ! config('triage.model')) {
            $this->error('API configuration is missing. No requests sent.');

            return self::FAILURE;
        }
        $evaluation = ['split' => 'DEV', 'run_id' => $this->option('run-id'),
            'source_sha256' => $manifest['source_sha256'], 'input_sha256' => hash('sha256', $raw),
            'order_seed' => (int) $this->option('seed'), 'order_algorithm' => 'sha256(seed:variant_id)',
            'prompt_sha256' => hash_file('sha256', resource_path('prompts/'.config('triage.prompt_version').'.txt')),
            'configuration_sha256' => hash('sha256', json_encode([
                config('triage.model'), config('triage.reasoning_effort'), config('triage.max_output_tokens'),
                config('triage.max_attempts'), config('triage.timeout_seconds'), config('triage.schema_version'),
                ScreeningOutput::VALIDATION_VERSION, ScreeningService::RETRY_FEEDBACK_VERSION,
                app(ScreeningOutput::class)->schema(),
            ], JSON_THROW_ON_ERROR))];
        $lock = Cache::lock('study:dev-baseline:'.$this->option('run-id'), 86400);
        if (! $lock->get()) {
            $this->error('This run is already locked by another process.');

            return self::FAILURE;
        }
        try {
            $existing = ScreeningSession::where('request_settings->evaluation->run_id', $this->option('run-id'))->get();
            foreach ($existing as $session) {
                if (($session->request_settings['evaluation'] ?? []) !== $evaluation
                    || ($session->request_settings['evaluation_method'] ?? null) !== 'method_a_only') {
                    $this->error('Run ID already belongs to different inputs/settings. Use a new run ID.');

                    return self::FAILURE;
                }
                if ($session->processing_status === 'processing') {
                    $this->error('An interrupted record requires inspection: '.$session->id.'. No automatic repeat.');

                    return self::FAILURE;
                }
                $this->saveHistory($session);
            }
            $done = $existing->pluck('variant_id')->all();
            $new = 0;
            foreach ($cases as $index => $case) {
                if (in_array($case['variant_id'], $done, true)) {
                    continue;
                }
                if ($this->option('limit') !== null && $new >= (int) $this->option('limit')) {
                    break;
                }
                $session = $service->screen($case, null, evaluateRules: false, evaluationMetadata: $evaluation);
                $this->saveHistory($session);
                $new++;
                $this->line(($index + 1).'/'.$cases->count().' '.$case['variant_id'].' '.$case['language'].' '.$session->id
                    .' '.($session->method_a_status ?? $session->processing_status).' '.($session->method_a_priority ?? $session->failure_code ?? 'null'));
                if (in_array($session->failure_code, ['credentials_rejected', 'request_rejected', 'configuration_missing', 'rate_limited'], true)) {
                    $this->error('Stopped on a provider/configuration error; the failed record is retained.');

                    return self::FAILURE;
                }
            }
            $this->info('Saved '.$new.' new records. Existing records were preserved; Method B was not called.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    private function saveHistory(ScreeningSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->refresh();
            if ($session->patient_visit_id !== null) {
                return;
            }
            $visit = PatientVisit::create(['language' => $session->language, 'status' => 'completed',
                'scope_confirmed_at' => now(),
                'answers' => $session->patient_input, 'question_index' => 15,
                'interview_state' => ['development' => ['configuration' => $session->request_settings['evaluation'],
                    'dataset_case_id' => $session->dataset_case_id, 'variant_id' => $session->variant_id,
                    'input_route' => 'original_workbook_structured_fields']]]);
            $visit->update(['stub_number' => 'FL-'.str_pad((string) $visit->id, 6, '0', STR_PAD_LEFT)]);
            $visit->messages()->create(['role' => 'assistant', 'source' => 'system',
                'content' => 'Original workbook DEV record · '.$session->dataset_case_id.' / '.$session->variant_id
                    .' · '.$session->request_settings['evaluation']['run_id'].'. LLM only; Method B was not evaluated. '
                    .'This transcript records a structured workbook submission, not an interactive patient interview.']);
            $visit->messages()->create(['role' => 'patient', 'source' => 'patient',
                'content' => json_encode($session->patient_input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'metadata' => ['input_route' => 'original_workbook_structured_fields']]);
            $text = $session->processing_status === 'technical_failure'
                ? 'Evaluation did not complete: '.$session->failure_code.'. No prediction was invented.'
                : ($session->method_a_priority === null ? 'Needs Review' : 'Predicted ESI '.$session->method_a_priority)
                    .'. '.($session->parsed_output['explanation'] ?? '');
            $visit->messages()->create(['role' => 'assistant',
                'source' => $session->processing_status === 'technical_failure' ? 'system' : 'model', 'content' => $text]);
            $session->update(['patient_visit_id' => $visit->id]);
        });
    }
}
