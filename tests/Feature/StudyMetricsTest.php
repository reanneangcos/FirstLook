<?php

namespace Tests\Feature;

use App\Services\Screening\StudyMetrics;
use App\Services\Screening\StudyStatistics;
use Tests\TestCase;

class StudyMetricsTest extends TestCase
{
    public function test_common_denominator_directional_errors_coverage_and_three_run_means(): void
    {
        $rows = [];
        foreach ([1, 2, 3] as $run) {
            foreach ([1, 2, 3, 4, 5] as $level) {
                $rows[] = ['run' => $run, 'dataset_case_id' => 'FICTIONAL', 'variant_id' => 'V'.$level, 'language' => 'L'.$level,
                    'reference' => $level, 'a_priority' => [1 => 1, 2 => 3, 3 => 2, 4 => null, 5 => null][$level],
                    'a_status' => match ($level) {
                        4 => 'needs_review', 5 => 'technical_failure', default => 'classified'
                    },
                    'b_priority' => $level === 5 ? null : $level, 'b_status' => $level === 5 ? 'upstream_failure' : 'classified'];
            }
        }
        $report = app(StudyMetrics::class)->calculate($rows);
        $run = $report['runs'][1];
        $this->assertSame(3, $run['common_pair_count']);
        $this->assertSame(0.6, $run['common_pair_coverage']);
        $this->assertSame(1 / 3, $run['common_pairs']['a']['accuracy']);
        $this->assertSame(1 / 3, $run['common_pairs']['a']['undertriage_rate']);
        $this->assertSame(1 / 3, $run['common_pairs']['a']['overtriage_rate']);
        $this->assertSame(1.0, $run['common_pairs']['b']['accuracy']);
        $this->assertSame(0.2, $run['common_pairs']['a']['macro_f1']);
        $this->assertSame(0.6, $run['common_pairs']['b']['macro_f1']);
        $this->assertSame(0.6, $run['methods']['a']['coverage']);
        $this->assertSame(0.8, $run['methods']['b']['coverage']);
        $this->assertSame(0.2, $run['methods']['a']['completion_adjusted_accuracy']);
        $this->assertSame(0.8, $run['methods']['b']['completion_adjusted_accuracy']);
        $this->assertSame(1, $run['methods']['a']['needs_review']);
        $this->assertSame(0, $run['methods']['b']['technical_failures']);
        $this->assertSame(1, $run['methods']['b']['upstream_failures']);
        $this->assertEqualsWithDelta(1 / 3, $report['three_run_mean']['a']['common_pairs']['undertriage_rate'], 1e-12);
        $this->assertSame(3, $report['repeatability']['a']['consistent_variants']);
        $this->assertSame(4, $report['repeatability']['b']['consistent_variants']);
        $this->assertSame(0.0, $run['language_consistency']['a']['rate']);
    }

    public function test_empty_pair_set_is_not_reported_as_perfect_or_zero_accuracy(): void
    {
        $rows = [];
        foreach ([1, 2, 3] as $run) {
            $rows[] = ['run' => $run, 'dataset_case_id' => 'FIXTURE', 'variant_id' => 'V1', 'language' => 'English',
                'reference' => 3, 'a_priority' => 3, 'a_status' => 'classified', 'b_priority' => null, 'b_status' => 'needs_review'];
        }
        $report = app(StudyMetrics::class)->calculate($rows);
        $this->assertNull($report['runs'][1]['common_pairs']['a']['accuracy']);
        $this->assertNull($report['runs'][1]['common_pairs']['b']['macro_f1']);
        $this->assertNull($report['three_run_mean']['b']['common_pairs']['undertriage_rate']);
        $this->assertSame(0.0, $report['runs'][1]['methods']['b']['completion_adjusted_accuracy']);
        $this->assertSame(1.0, $report['runs'][1]['methods']['b']['needs_review_rate']);
    }

    public function test_language_consistency_and_repeatability_require_all_numeric_predictions(): void
    {
        $rows = [];
        foreach ([1, 2, 3] as $run) {
            foreach (range(1, 5) as $variant) {
                $missing = $run === 1 && $variant === 1;
                $rows[] = ['run' => $run, 'dataset_case_id' => 'FIXTURE', 'variant_id' => 'V'.$variant, 'language' => 'L'.$variant,
                    'reference' => 3, 'a_priority' => $missing ? null : 3, 'a_status' => $missing ? 'needs_review' : 'classified',
                    'b_priority' => 3, 'b_status' => 'classified'];
            }
        }
        $report = app(StudyMetrics::class)->calculate($rows);
        $this->assertSame(0, $report['runs'][1]['language_consistency']['a']['consistent_cases']);
        $this->assertSame(1, $report['runs'][2]['language_consistency']['a']['consistent_cases']);
        $this->assertSame(4, $report['repeatability']['a']['consistent_variants']);
        $this->assertSame(5, $report['repeatability']['b']['consistent_variants']);
        $this->assertSame(2 / 3, $report['three_run_mean']['a']['language_consistency']);
    }

    public function test_needs_review_cannot_be_encoded_as_a_sixth_priority(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(StudyMetrics::class)->calculate([['run' => 1, 'variant_id' => 'V1', 'reference' => 3,
            'a_status' => 'needs_review', 'a_priority' => 6, 'b_status' => 'classified', 'b_priority' => 3]]);
    }

    public function test_duplicate_attempts_cannot_be_counted_as_separate_observations(): void
    {
        $row = ['run' => 1, 'dataset_case_id' => 'FIXTURE', 'variant_id' => 'V1', 'language' => 'English', 'reference' => 3,
            'a_status' => 'classified', 'a_priority' => 3, 'b_status' => 'classified', 'b_priority' => 3];
        $this->expectException(\InvalidArgumentException::class);
        app(StudyMetrics::class)->calculate([$row, $row]);
    }

    public function test_bootstrap_keeps_all_fifteen_observations_together_and_discloses_undefined_samples(): void
    {
        $rows = [];
        foreach ([1, 2, 3] as $run) {
            foreach (range(1, 5) as $variant) {
                $rows[] = ['run' => $run, 'dataset_case_id' => 'FIXTURE', 'variant_id' => 'V'.$variant, 'language' => 'L'.$variant,
                    'reference' => 3, 'a_status' => 'classified', 'a_priority' => 1, 'b_status' => 'classified', 'b_priority' => 3];
            }
        }
        $statistics = app(StudyStatistics::class);
        $report = $statistics->bootstrap($rows, 20);
        $this->assertSame($report, $statistics->bootstrap($rows, 20));
        $this->assertSame(1, $report['canonical_groups']);
        $this->assertSame(1.0, $report['intervals']['accuracy']['difference_b_minus_a']);
        $this->assertSame(1.0, $report['intervals']['accuracy']['lower_95']);
        $this->assertSame(1.0, $report['intervals']['accuracy']['upper_95']);
        foreach ($rows as &$row) {
            $row['b_status'] = 'needs_review';
            $row['b_priority'] = null;
        }
        unset($row);
        $report = $statistics->bootstrap($rows, 20);
        $this->assertSame(20, $report['intervals']['accuracy']['undefined_resamples']);
        $this->assertNull($report['intervals']['accuracy']['lower_95']);
        $this->assertSame(-1.0, $report['intervals']['coverage']['difference_b_minus_a']);
    }

    public function test_mcnemar_uses_prespecified_english_pairs_and_correct_exact_and_asymptotic_tails(): void
    {
        $rows = [];
        foreach (range(1, 100) as $case) {
            $rows[] = ['run' => 1, 'dataset_case_id' => 'C'.$case, 'variant_id' => 'V'.$case, 'language' => 'English',
                'reference' => 3, 'a_status' => 'classified', 'a_priority' => $case <= 60 ? 3 : 4,
                'b_status' => 'classified', 'b_priority' => $case <= 60 ? 4 : 3];
        }
        $statistics = app(StudyStatistics::class);
        $result = $statistics->mcnemar($rows, 1);
        $this->assertSame(60, $result['a_correct_b_incorrect']);
        $this->assertSame(40, $result['a_incorrect_b_correct']);
        $this->assertEqualsWithDelta(3.61, $result['chi_square'], 1e-12);
        $this->assertEqualsWithDelta(0.0574331196320036, $result['p_value'], 1e-8);
        $small = [...array_slice($rows, 0, 8), ...array_slice($rows, 60, 2)];
        $result = $statistics->mcnemar($small, 1);
        $this->assertSame('exact_two_sided_binomial', $result['method']);
        $this->assertSame(0.109375, $result['p_value']);
        $this->assertNull($statistics->mcnemar($small, 2)['p_value']);
    }
}
