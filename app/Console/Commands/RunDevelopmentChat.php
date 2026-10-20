<?php

namespace App\Console\Commands;

use App\Models\PatientVisit;
use App\Services\Screening\PatientChatService;
use App\Services\Screening\PatientFields;
use App\Services\Screening\ScreeningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

#[Signature('study:dev-chat {input : Audited DEV paragraph manifest} {--run-id=} {--shards=1} {--shard=0} {--limit=} {--dry-run}')]
#[Description('Exercise DEV paragraphs through LLM intake and Method A, saving real admin-visible conversations')]
class RunDevelopmentChat extends Command
{
    public function handle(PatientChatService $chat, ScreeningService $screening): int
    {
        $path = $this->argument('input');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Manifest is not readable.');

            return self::FAILURE;
        }
        $raw = file_get_contents($path);
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('Manifest must be valid JSON.');

            return self::FAILURE;
        }
        $rules = [
            'manifest' => ['required', 'array:split,source_sha256,paragraph_sha256,cases'],
            'manifest.split' => ['required', Rule::in(['DEV'])],
            'manifest.source_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'manifest.paragraph_sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'manifest.cases' => ['required', 'array', 'list', 'min:1'],
            'manifest.cases.*' => ['required', 'array:split,dataset_case_id,variant_id,language,patient,paragraph'],
            'manifest.cases.*.split' => ['required', Rule::in(['DEV'])],
            'manifest.cases.*.dataset_case_id' => ['required', 'regex:/^[A-Za-z0-9_.-]{1,80}$/'],
            'manifest.cases.*.variant_id' => ['required', 'distinct', 'regex:/^[A-Za-z0-9_.-]{1,80}$/'],
            'manifest.cases.*.language' => ['required', Rule::in(['English', 'Tagalog', 'Bisaya', 'English–Tagalog', 'English–Bisaya', 'Tagalog–Bisaya'])],
            'manifest.cases.*.paragraph' => ['required', 'string', 'max:3000'],
            'manifest.cases.*.patient' => ['required', 'array:'.implode(',', PatientFields::NAMES)],
            'run_id' => ['required', 'regex:/^[A-Za-z0-9_.-]{1,80}$/'],
            'shards' => ['required', 'integer', 'min:1', 'max:4'],
            'shard' => ['required', 'integer', 'min:0', 'lt:shards'],
            'limit' => ['nullable', 'integer', 'min:1'],
        ];
        foreach (PatientFields::NAMES as $field) {
            $rules['manifest.cases.*.patient.'.$field] = $field === 'age'
                ? ['present', 'nullable', 'integer', 'min:18'] : ['present', 'nullable', 'string', 'max:3000'];
        }
        $validator = Validator::make(['manifest' => $manifest, 'run_id' => $this->option('run-id'),
            'shards' => $this->option('shards'), 'shard' => $this->option('shard'), 'limit' => $this->option('limit')], $rules);
        if ($validator->fails()) {
            $this->error('Invalid DEV manifest/options: '.implode(', ', $validator->errors()->keys()));

            return self::FAILURE;
        }
        $cases = collect($manifest['cases'])->sortBy(fn (array $case): string => hash('sha256', '42:'.$case['variant_id']))->values();
        $this->info('DEV chat: '.$cases->count().' paragraph versions; Method A only; worker '.($this->option('shard') + 1).'/'.$this->option('shards').'.');
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! config('triage.api_key') || ! config('triage.model')) {
            $this->error('API configuration is missing.');

            return self::FAILURE;
        }
        /** Acquire SQLite's write reservation before transaction reads when workers share the local database. */
        if ((int) $this->option('shards') > 1 && config('database.default') === 'sqlite' && ! app()->runningUnitTests()) {
            config(['database.connections.sqlite.transaction_mode' => 'IMMEDIATE', 'database.connections.sqlite.busy_timeout' => 10000]);
            DB::purge('sqlite');
        }
        $configuration = ['run_id' => $this->option('run-id'), 'split' => 'DEV', 'method' => 'method_a_only',
            'input_sha256' => hash('sha256', $raw), 'source_sha256' => $manifest['source_sha256'],
            'paragraph_sha256' => $manifest['paragraph_sha256'], 'shards' => (int) $this->option('shards'),
            'intake_version' => config('triage.intake_version'), 'model' => config('triage.model'),
            'intake_prompt_sha256' => hash_file('sha256', resource_path('prompts/'.config('triage.intake_version').'.txt')),
            'screening_prompt_sha256' => hash_file('sha256', resource_path('prompts/'.config('triage.patient_prompt_version').'.txt')),
            'settings_sha256' => hash('sha256', json_encode(array_diff_key(config('triage'), ['api_key' => true]), JSON_THROW_ON_ERROR)),
            'order_seed' => 42, 'order_algorithm' => 'sha256(seed:variant_id)'];
        $lock = Cache::lock('study:dev-chat:'.$this->option('run-id').':'.$this->option('shard'), 86400);
        if (! $lock->get()) {
            $this->error('This worker is already running.');

            return self::FAILURE;
        }
        try {
            $capturingIntake = false;
            $intakeResponse = null;
            $intakeHttpStatus = null;
            Event::listen(ResponseReceived::class, function (ResponseReceived $event) use (&$capturingIntake, &$intakeResponse, &$intakeHttpStatus): void {
                if ($capturingIntake && $event->request->url() === 'https://api.openai.com/v1/responses') {
                    $intakeHttpStatus = $event->response->status();
                    $intakeResponse = $event->response->successful() ? $event->response->body() : null;
                }
            });
            $existing = PatientVisit::where('interview_state->development->configuration->run_id', $this->option('run-id'))->get();
            foreach ($existing as $visit) {
                if ($visit->interview_state['development']['configuration'] !== $configuration) {
                    $this->error('Inputs/settings changed. Use a new run ID to preserve earlier findings.');

                    return self::FAILURE;
                }
            }
            $done = $existing->map(fn (PatientVisit $visit): string => $visit->interview_state['development']['variant_id'])->all();
            $new = 0;
            foreach ($cases as $index => $case) {
                if ($index % (int) $this->option('shards') !== (int) $this->option('shard') || in_array($case['variant_id'], $done, true)) {
                    continue;
                }
                if ($this->option('limit') !== null && $new >= (int) $this->option('limit')) {
                    break;
                }
                $language = str_contains($case['paragraph'], 'Pangunang reklamo:') ? 'Bisaya'
                    : (str_contains($case['paragraph'], 'Pangunahing reklamo:') ? 'Tagalog' : 'English');
                $visit = $chat->start($language)->fresh();
                $development = ['configuration' => $configuration, 'dataset_case_id' => $case['dataset_case_id'],
                    'variant_id' => $case['variant_id'], 'input_language' => $case['language'], 'response_language' => $language,
                    'intake_status' => 'processing', 'source_patient' => $case['patient']];
                $visit->update(['interview_state' => $visit->interview_state + ['development' => $development]]);
                $visit->messages()->create(['role' => 'assistant', 'source' => 'system',
                    'content' => 'Development check · '.$case['dataset_case_id'].' / '.$case['variant_id'].' · '.$case['language'].' · '.$this->option('run-id').'. LLM only; Method B is not evaluated.',
                    'metadata' => ['development' => $development]]);
                $question = $chat->currentQuestion($visit);
                $capturingIntake = true;
                $intakeResponse = null;
                $intakeHttpStatus = null;
                try {
                    $chat->answer($visit, ['message' => $case['paragraph'], 'skip' => false,
                        'field' => $question['field'], 'question_revision' => $question['revision']]);
                    $visit->refresh();
                    $development['intake_status'] = $visit->status === 'ready' ? 'ready' : 'clarification_required';
                } catch (ValidationException) {
                    $visit->refresh();
                    $development['intake_status'] = 'technical_failure';
                    $development['failure_code'] = $visit->interview_state['last_intake_failure']['code'] ?? 'input_validation';
                    $visit->messages()->create(['role' => 'patient', 'source' => 'patient', 'field' => $question['field'],
                        'content' => $case['paragraph'], 'metadata' => ['accepted' => false, 'failure_code' => $development['failure_code']]]);
                    $visit->messages()->create(['role' => 'assistant', 'source' => 'system',
                        'content' => 'Development intake failed ('.$development['failure_code'].'). The original paragraph is retained; no answers or priority were invented.']);
                } finally {
                    $capturingIntake = false;
                }
                $development['intake_http_status'] = $intakeHttpStatus;
                $development['original_intake_response'] = $intakeResponse;
                $development['addressed_fields'] = array_keys($visit->answers);
                $development['missing_fields'] = array_values(array_diff(PatientFields::NAMES, array_keys($visit->answers)));
                $visit->update(['interview_state' => array_replace($visit->interview_state, ['development' => $development])]);
                if ($visit->status === 'ready') {
                    $chat->screen($visit, $screening, evaluateRules: false,
                        evaluationMetadata: $configuration + ['dataset_case_id' => $case['dataset_case_id'], 'variant_id' => $case['variant_id'],
                            'input_language' => $case['language'], 'response_language' => $language, 'input_route' => 'paragraph_then_llm_intake']);
                }
                $new++;
                $visit->refresh()->load('screening');
                $this->line(($index + 1).'/'.$cases->count().' '.$case['variant_id'].' '.$visit->stub_number.' '.$development['intake_status']
                    .' fields='.count($visit->answers).' result='.($visit->screening?->method_a_priority ?? $visit->screening?->method_a_status ?? $development['failure_code'] ?? 'pending'));
                if (in_array($visit->screening?->failure_code, ['credentials_rejected', 'rate_limited', 'request_rejected', 'configuration_missing'], true)
                    || ($development['failure_code'] ?? null) === 'provider_unavailable') {
                    $this->error('Stopped on provider/configuration failure; saved history is retained.');

                    return self::FAILURE;
                }
            }
            $this->info('Saved '.$new.' development conversations. Existing visits were not repeated.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
