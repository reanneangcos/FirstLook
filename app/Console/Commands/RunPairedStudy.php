<?php

namespace App\Console\Commands;

use App\Models\PatientVisit;
use App\Models\ScreeningSession;
use App\Services\Screening\ScreeningService;
use App\Services\Screening\StudyProtocol;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

#[Signature('study:run {study : Frozen study ID} {--run= : Repetition 1, 2 or 3} {--limit= : Maximum new records this invocation} {--dry-run : Check the freeze without requests or writes}')]
#[Description('Save independent Method A and deterministic Method B outputs for one frozen repetition')]
class RunPairedStudy extends Command
{
    public function handle(StudyProtocol $protocol, ScreeningService $service): int
    {
        try {
            $freeze = $protocol->frozen($this->argument('study'));
            $payload = $freeze['payload'];
            $run = filter_var($this->option('run'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
            $limit = $this->option('limit') === null ? 600 : filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 600]]);
            if ($run === false || $limit === false) {
                throw new RuntimeException('Choose run 1, 2 or 3 and an optional limit of 1–600.');
            }
            $seed = $payload['seeds'][$run - 1];
            $cases = collect($payload['manifest']['cases'])->sortBy(fn (array $case): string => hash('sha256', $seed.':'.$case['variant_id']))->values();
            if ($this->option('dry-run')) {
                $this->info('Frozen configuration verified. Run '.$run.': 600 versions, order seed '.$seed.'. No requests or writes.');

                return self::SUCCESS;
            }
            $lock = Cache::lock('study:'.$payload['study_id'], 86400);
            if (! $lock->get()) {
                throw new RuntimeException('Study is locked by another process.');
            }
            try {
                $evaluation = ['split' => 'TEST', 'study_id' => $payload['study_id'], 'run' => $run,
                    'run_id' => $payload['study_id'].'-r'.$run, 'freeze_sha256' => $freeze['sha256'],
                    'input_sha256' => $payload['manifest_sha256'], 'order_seed' => $seed,
                    'order_algorithm' => $payload['order_algorithm'], 'protocol_version' => StudyProtocol::VERSION];
                $existing = ScreeningSession::where('request_settings->evaluation->study_id', $payload['study_id'])
                    ->where('request_settings->evaluation->run', $run)->get();
                if ($existing->pluck('variant_id')->unique()->count() !== $existing->count()) {
                    throw new RuntimeException('Duplicate saved variants require inspection. No automatic repeat.');
                }
                foreach ($existing as $session) {
                    $case = $cases->firstWhere('variant_id', $session->variant_id);
                    if (($session->request_settings['evaluation'] ?? null) !== $evaluation
                        || ($session->request_settings['evaluation_method'] ?? null) !== 'paired' || $case === null
                        || $session->dataset_case_id !== $case['dataset_case_id'] || $session->original_input !== $case['patient']) {
                        throw new RuntimeException('Existing run records do not match the freeze.');
                    }
                    if ($session->processing_status === 'processing'
                        || ($session->processing_status === 'completed' && $session->method_b_status === 'not_evaluated')) {
                        throw new RuntimeException('Interrupted record '.$session->id.' requires inspection; neither method will be silently rerun.');
                    }
                    $this->saveHistory($session);
                }
                $done = $existing->pluck('variant_id')->all();
                $new = 0;
                foreach ($cases as $index => $case) {
                    if (in_array($case['variant_id'], $done, true)) {
                        continue;
                    }
                    if ($new >= $limit) {
                        break;
                    }
                    if (StudyProtocol::hash($protocol->snapshot()) !== StudyProtocol::hash($payload['snapshot'])) {
                        throw new RuntimeException('Configuration/code changed during the run. Stopped before the next request.');
                    }
                    $session = $service->screen($case, null, evaluateRules: true, evaluationMetadata: $evaluation, studyCriteria: true);
                    $this->saveHistory($session);
                    $new++;
                    $this->line(($index + 1).'/600 '.$case['variant_id'].' A:'.($session->method_a_priority ?? $session->method_a_status ?? $session->processing_status)
                        .' B:'.($session->method_b_priority ?? $session->method_b_status));
                    if (in_array($session->failure_code, ['credentials_rejected', 'request_rejected', 'configuration_missing', 'rate_limited'], true)) {
                        throw new RuntimeException('Stopped on a provider/configuration error. The failure is saved and will not be retried as a new case.');
                    }
                }
                $this->info('Saved '.$new.' new paired records with admin history. Existing outputs were preserved.');
            } finally {
                $lock->release();
            }
        } catch (RuntimeException|\JsonException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function saveHistory(ScreeningSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $session->refresh();
            if ($session->patient_visit_id !== null) {
                return;
            }
            $evaluation = $session->request_settings['evaluation'];
            $visit = PatientVisit::create(['language' => $session->language, 'status' => 'completed',
                'scope_confirmed_at' => now(), 'answers' => $session->patient_input, 'question_index' => 15,
                'interview_state' => ['study' => ['configuration' => $evaluation, 'dataset_case_id' => $session->dataset_case_id,
                    'variant_id' => $session->variant_id, 'input_route' => 'original_workbook_structured_fields']]]);
            $visit->update(['stub_number' => 'FL-'.str_pad((string) $visit->id, 6, '0', STR_PAD_LEFT)]);
            $visit->messages()->create(['role' => 'assistant', 'source' => 'system',
                'content' => 'Frozen TEST submission · '.$evaluation['run_id'].' · '.$session->dataset_case_id.' / '.$session->variant_id
                    .'. Structured workbook record; this is not an interactive interview. Both methods are saved separately.']);
            $visit->messages()->create(['role' => 'patient', 'source' => 'patient',
                'content' => json_encode($session->patient_input, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            $visit->messages()->create(['role' => 'assistant', 'source' => 'system',
                'content' => 'Method A: '.($session->method_a_priority === null ? ($session->method_a_status ?? $session->processing_status) : 'Predicted ESI '.$session->method_a_priority)
                    .'. '.($session->parsed_output['explanation'] ?? $session->failure_message ?? '')
                    .' Method B: '.($session->method_b_result['display_label'] ?? $session->method_b_status)
                    .'. '.($session->method_b_result['explanation'] ?? 'No accepted LLM facts were available for the rule layer.')]);
            $session->update(['patient_visit_id' => $visit->id]);
        });
    }
}
