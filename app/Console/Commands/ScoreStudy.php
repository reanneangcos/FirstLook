<?php

namespace App\Console\Commands;

use App\Models\ScreeningSession;
use App\Services\Screening\StudyErrorReview;
use App\Services\Screening\StudyManifest;
use App\Services\Screening\StudyMetrics;
use App\Services\Screening\StudyProtocol;
use App\Services\Screening\StudyStatistics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

#[Signature('study:score {study : Frozen study ID} {reference : The frozen separate reference-key JSON} {--output=results-v1 : New report name; existing reports are preserved}')]
#[Description('Join reference labels locally after all three repetitions and export paired results; no LLM requests')]
class ScoreStudy extends Command
{
    public function handle(StudyProtocol $protocol, StudyManifest $loader, StudyMetrics $metrics, StudyStatistics $statistics, StudyErrorReview $errorReview): int
    {
        $reviewStream = fopen('php://temp', 'w+');
        try {
            $freeze = $protocol->frozen($this->argument('study'), checkCurrentConfiguration: false);
            $payload = $freeze['payload'];
            $output = (string) $this->option('output');
            StudyManifest::validateId($output);
            $base = 'studies/'.$payload['study_id'].'/'.$output;
            if (Storage::disk('local')->exists($base.'.json') || Storage::disk('local')->exists($base.'.csv') || Storage::disk('local')->exists($base.'-error-review.csv')) {
                throw new RuntimeException('Report already exists; choose a new --output name.');
            }
            $referencePath = $this->argument('reference');
            if (! is_file($referencePath) || hash_file('sha256', $referencePath) !== $payload['reference_sha256']) {
                throw new RuntimeException('Reference file changed or is missing. Use the key frozen before testing.');
            }
            $references = $loader->references($referencePath, $payload['manifest']);
            $sessions = ScreeningSession::where('request_settings->evaluation->study_id', $payload['study_id']);
            $count = $sessions->count();
            if ($count !== 1800) {
                throw new RuntimeException('Final results require all 1,800 scheduled records; found '.$count.'. Do not score a partial run as final.');
            }
            $cases = collect($payload['manifest']['cases'])->keyBy('variant_id');
            $rows = [];
            $reviewCount = 0;
            foreach ($sessions->lazyById(50) as $session) {
                $evaluation = $session->request_settings['evaluation'] ?? [];
                $case = $cases->get($session->variant_id);
                $run = $evaluation['run'] ?? null;
                if ($case === null || ! in_array($run, [1, 2, 3], true)
                    || ($evaluation['freeze_sha256'] ?? null) !== $freeze['sha256']
                    || ($session->request_settings['evaluation_method'] ?? null) !== 'paired'
                    || $session->dataset_case_id !== $case['dataset_case_id'] || $session->language !== $case['language']
                    || $session->original_input !== $case['patient'] || $session->is_fixture
                    || $session->prompt_version !== $payload['snapshot']['prompt_version']
                    || hash('sha256', $session->prompt_text) !== $payload['snapshot']['prompt_sha256']
                    || $session->patient_visit_id === null) {
                    throw new RuntimeException('Saved record does not match frozen inputs, settings or provenance: '.$session->id);
                }
                if (! in_array($session->processing_status, ['completed', 'technical_failure'], true)) {
                    throw new RuntimeException('Interrupted record requires inspection: '.$session->id);
                }
                $settings = $session->request_settings;
                foreach ($payload['snapshot']['settings'] as $key => $value) {
                    $saved = match ($key) {
                        'reasoning_effort' => $settings['reasoning']['effort'] ?? null,
                        default => $settings[$key] ?? null,
                    };
                    if ($saved !== $value) {
                        throw new RuntimeException('Saved request setting differs from the freeze: '.$key.' in '.$session->id);
                    }
                }
                if (($evaluation['run_id'] ?? null) !== $payload['study_id'].'-r'.$run
                    || ($evaluation['order_seed'] ?? null) !== $payload['seeds'][$run - 1]
                    || ($evaluation['split'] ?? null) !== 'TEST'
                    || ($evaluation['input_sha256'] ?? null) !== $payload['manifest_sha256']
                    || ($settings['validation_version'] ?? null) !== $payload['snapshot']['validation_version']
                    || ($settings['retry_feedback_version'] ?? null) !== $payload['snapshot']['retry_feedback_version']) {
                    throw new RuntimeException('Saved protocol metadata differs from the freeze: '.$session->id);
                }
                $failed = $session->processing_status === 'technical_failure';
                if (! $failed && ($session->method_b_status === 'not_evaluated'
                    || $session->method_b_rule_version !== $payload['snapshot']['rule_version']
                    || $session->method_b_input !== ['extracted_facts' => $session->parsed_output['extracted_facts'], 'normalized_facts' => $session->parsed_output['normalized_facts'], 'clinical_assessment' => []]
                    || $session->method_a_priority !== $session->parsed_output['priority']
                    || $session->method_a_status !== $session->parsed_output['status']
                    || $session->method_b_priority !== ($session->method_b_result['priority'] ?? null)
                    || $session->method_b_status !== ($session->method_b_result['status'] ?? null))) {
                    throw new RuntimeException('Incomplete or inconsistent A/B pair: '.$session->id);
                }
                $row = ['run' => $run, 'dataset_case_id' => $session->dataset_case_id, 'variant_id' => $session->variant_id,
                    'language' => $session->language, 'reference' => $references[$session->variant_id],
                    'a_status' => $failed ? 'technical_failure' : $session->method_a_status,
                    'a_priority' => $session->method_a_priority,
                    'b_status' => $failed ? 'upstream_failure' : $session->method_b_status, 'b_priority' => $session->method_b_priority,
                    'screening_id' => $session->id, 'patient_visit_id' => $session->patient_visit_id,
                    'a_failure_code' => $session->failure_code, 'b_stopped_at' => $session->method_b_result['stopped_at'] ?? null,
                    'returned_model' => $session->returned_model, 'rule_version' => $session->method_b_rule_version,
                    'shared_stage_ms' => $session->latency_ms === null ? null : (int) $session->latency_ms,
                    'rule_stage_wall_ms' => $settings['runtime_measurements']['rule_stage_wall_ms'] ?? null];
                $rows[] = $row;
                $review = $errorReview->row($session, $row);
                if ($review !== null) {
                    if ($reviewCount === 0) {
                        fputcsv($reviewStream, array_keys($review), escape: '');
                    }
                    $errorReview->writeCsvRow($reviewStream, $review);
                    $reviewCount++;
                }
            }
            usort($rows, fn (array $a, array $b): int => [$a['run'], $a['variant_id']] <=> [$b['run'], $b['variant_id']]);
            $report = $metrics->calculate($rows);
            $report['paired_bootstrap'] = $statistics->bootstrap($rows, $payload['statistics']['bootstrap_resamples'], $payload['statistics']['bootstrap_seed']);
            $report['secondary_mcnemar'] = $statistics->mcnemar($rows, $payload['statistics']['secondary_run'], $payload['statistics']['mcnemar_exact_below_discordant']);
            $report['study_id'] = $payload['study_id'];
            $report['freeze_sha256'] = $freeze['sha256'];
            $report['reference_sha256'] = $payload['reference_sha256'];
            $report['frozen_metrics_version'] = $payload['statistics']['metrics_version'] ?? 'paired-metrics-v1';
            $report['scoring_code_sha256'] = [];
            foreach ([__FILE__, app_path('Services/Screening/StudyMetrics.php'), app_path('Services/Screening/StudyStatistics.php'), app_path('Services/Screening/StudyErrorReview.php')] as $file) {
                $report['scoring_code_sha256'][substr($file, strlen(base_path()) + 1)] = hash_file('sha256', $file);
            }
            $report['method_definitions'] = [
                'a' => 'Prompt-based rule-execution baseline: the LLM predicts using the frozen criteria in its prompt.',
                'b' => 'Deterministic execution of the criteria using the same saved LLM-extracted facts and normalized concepts; A priority is not an engine input.',
                'shared' => 'One LLM response supplies A and the facts for B. The methods are paired, not independent LLM systems.',
            ];
            $report['qualitative_review'] = ['selected_observations' => $reviewCount,
                'selection' => 'All reference mismatches, unresolved outputs, failures and method disagreements; labels are joined offline.',
                'suggested_human_categories' => StudyErrorReview::CATEGORIES,
                'note' => 'Selection flags describe numerical outcomes only. Human error category, notes, reviewer and date are intentionally blank; extraction accuracy requires independently annotated facts.'];
            $disputed = $payload['sensitivity']['disputed_cases'] ?? [];
            $excludedIds = array_column($disputed, 'dataset_case_id');
            $report['sensitivity'] = ['prespecified_disputed_cases' => $disputed,
                'register_sha256' => $payload['sensitivity']['register_sha256'] ?? null,
                'excluded_canonical_cases' => count($excludedIds), 'excluded_observations' => 15 * count($excludedIds),
                'note' => 'Primary results retain every case and original reference. Supplementary results exclude only cases registered before freezing, across both methods, all languages and all runs.'];
            if ($excludedIds !== []) {
                $retained = array_values(array_filter($rows, fn (array $row): bool => ! in_array($row['dataset_case_id'], $excludedIds, true)));
                $report['sensitivity']['results'] = $metrics->calculate($retained);
                $report['sensitivity']['paired_bootstrap'] = $statistics->bootstrap($retained, $payload['statistics']['bootstrap_resamples'], $payload['statistics']['bootstrap_seed']);
                $report['sensitivity']['secondary_mcnemar'] = $statistics->mcnemar($retained, $payload['statistics']['secondary_run'], $payload['statistics']['mcnemar_exact_below_discordant']);
            }
            $report['returned_models'] = array_values(array_unique(array_filter(array_column($rows, 'returned_model'))));
            $report['model_drift_warning'] = count($report['returned_models']) > 1 ? 'Multiple returned model identifiers; inspect before interpreting repeatability.' : null;
            rewind($reviewStream);
            if (! Storage::disk('local')->put($base.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
                || ! Storage::disk('local')->put($base.'.csv', $errorReview->csv($rows))
                || ! Storage::disk('local')->put($base.'-error-review.csv', $reviewStream)) {
                throw new RuntimeException('Report write failed; inspect the output directory.');
            }
            $this->info('Saved '.$base.'.json, .csv and -error-review.csv. No patient records or predictions were changed.');
        } catch (RuntimeException|\JsonException|\InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($reviewStream)) {
                fclose($reviewStream);
            }
        }

        return self::SUCCESS;
    }
}
