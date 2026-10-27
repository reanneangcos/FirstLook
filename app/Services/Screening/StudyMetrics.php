<?php

namespace App\Services\Screening;

use InvalidArgumentException;

final class StudyMetrics
{
    public const VERSION = 'paired-metrics-v2';

    /** @param list<array<string, mixed>> $rows */
    public function calculate(array $rows): array
    {
        $keys = [];
        $variants = [];
        $references = [];
        foreach ($rows as $row) {
            $key = $row['run'].':'.$row['variant_id'];
            if (isset($keys[$key]) || ! in_array($row['run'], [1, 2, 3], true)
                || ! in_array($row['reference'], [1, 2, 3, 4, 5], true)) {
                throw new InvalidArgumentException('Duplicate observation or invalid run/reference.');
            }
            foreach (['a', 'b'] as $method) {
                $status = $row[$method.'_status'];
                $priority = $row[$method.'_priority'];
                if (! in_array($status, ['classified', 'needs_review', 'technical_failure', 'upstream_failure'], true)
                    || ($status === 'classified' ? ! in_array($priority, [1, 2, 3, 4, 5], true) : $priority !== null)) {
                    throw new InvalidArgumentException('Invalid status/priority pair. Needs Review is not an ESI level.');
                }
            }
            $identity = [$row['dataset_case_id'], $row['language'], $row['reference']];
            if ((isset($variants[$row['variant_id']]) && $variants[$row['variant_id']] !== $identity)
                || (isset($references[$row['dataset_case_id']]) && $references[$row['dataset_case_id']] !== $row['reference'])) {
                throw new InvalidArgumentException('Case identity, language and reference must remain consistent across versions and runs.');
            }
            $variants[$row['variant_id']] = $identity;
            $references[$row['dataset_case_id']] = $row['reference'];
            $keys[$key] = true;
        }
        $runs = [];
        foreach (collect($rows)->groupBy('run')->sortKeys() as $number => $group) {
            $runRows = $group->all();
            $runs[$number] = $this->summarize($runRows);
            $runs[$number]['runtime'] = $this->runtime($runRows);
            $runs[$number]['language_consistency'] = [];
            foreach (['a', 'b'] as $method) {
                $groups = $group->groupBy('dataset_case_id');
                $consistent = $groups->filter(fn ($items): bool => $items->count() === 5
                    && $items->pluck('language')->unique()->count() === 5 && $this->consistent($items->all(), $method))->count();
                $correct = $groups->filter(fn ($items): bool => $items->count() === 5
                    && $items->pluck('language')->unique()->count() === 5
                    && array_all($items->all(), fn (array $row): bool => $row[$method.'_status'] === 'classified'
                        && $row[$method.'_priority'] === $row['reference']))->count();
                $runs[$number]['language_consistency'][$method] = ['consistent_cases' => $consistent,
                    'total_cases' => $groups->count(), 'rate' => $this->ratio($consistent, $groups->count()),
                    'correct_consistent_cases' => $correct, 'correct_consistency_rate' => $this->ratio($correct, $groups->count())];
            }
            foreach (['language' => 'by_language', 'reference' => 'by_reference_esi'] as $field => $name) {
                $runs[$number][$name] = $group->groupBy($field)->map(fn ($items): array => $this->summarize($items->all()))->all();
            }
        }
        $means = [];
        $means['common_pair_coverage'] = $this->mean(array_column($runs, 'common_pair_coverage'));
        foreach (['a', 'b'] as $method) {
            foreach (['accuracy', 'macro_f1', 'misclassification_rate', 'undertriage_rate', 'overtriage_rate', 'one_level_undertriage_rate', 'severe_undertriage_rate'] as $metric) {
                $means[$method]['common_pairs'][$metric] = $this->mean(array_map(fn (array $run): ?float => $run['common_pairs'][$method][$metric], $runs));
            }
            foreach (['coverage', 'needs_review_rate', 'technical_failure_rate', 'upstream_failure_rate', 'completion_adjusted_accuracy'] as $metric) {
                $means[$method][$metric] = $this->mean(array_map(fn (array $run): ?float => $run['methods'][$method][$metric], $runs));
            }
            $means[$method]['language_consistency'] = $this->mean(array_map(fn (array $run): ?float => $run['language_consistency'][$method]['rate'], $runs));
            $means[$method]['correct_language_consistency'] = $this->mean(array_map(fn (array $run): ?float => $run['language_consistency'][$method]['correct_consistency_rate'], $runs));
            foreach (range(1, 5) as $level) {
                $means[$method]['common_pairs']['recall_by_esi'][$level] = $this->mean(array_map(fn (array $run): ?float => $run['common_pairs'][$method]['recall_by_esi'][$level], $runs));
                $means[$method]['completion_adjusted_recall_by_esi'][$level] = $this->mean(array_map(fn (array $run): ?float => $run['methods'][$method]['completion_adjusted_recall_by_esi'][$level], $runs));
            }
        }
        $repeatability = [];
        $variants = collect($rows)->groupBy('variant_id');
        foreach (['a', 'b'] as $method) {
            $consistent = $variants->filter(fn ($items): bool => $items->count() === 3
                && $items->pluck('run')->unique()->count() === 3 && $this->consistent($items->all(), $method))->count();
            $repeatability[$method] = ['consistent_variants' => $consistent, 'total_variants' => $variants->count(),
                'rate' => $this->ratio($consistent, $variants->count())];
        }

        return ['metrics_version' => self::VERSION, 'observations' => count($rows), 'runs' => $runs,
            'three_run_mean' => count($runs) === 3 ? $means : null, 'repeatability' => $repeatability,
            'runtime' => $this->runtime($rows),
            'definitions' => ['rates' => 'Fractions from 0 to 1. Null means not estimable.',
                'conditional_metrics' => 'Both methods use the same rows where A and B each have a classified numerical priority.',
                'undertriage' => 'Predicted ESI is numerically greater than the reference (less urgent).',
                'severe_undertriage' => 'Predicted ESI minus reference ESI is at least two. Rate denominator is the common numerical A/B pairs; includes ESI 1 predicted as 3–5.',
                'one_level_undertriage' => 'Predicted ESI minus reference ESI equals one; disjoint from severe undertriage.',
                'recall_by_esi' => 'Correct predictions of a reference class divided by common numerical pairs in that reference class. Null when the class has no paired support.',
                'completion_adjusted_recall_by_esi' => 'Correct predictions of a reference class divided by all scheduled inputs of that class, including unresolved outputs and failures. Null when the class has no scheduled support.',
                'overtriage' => 'Predicted ESI is numerically lower than the reference (more urgent).',
                'macro_f1' => 'Arithmetic mean across all five ESI classes; a class with zero TP, FP and FN contributes 0. Empty paired sets yield null.',
                'completion_adjusted_accuracy' => 'Correct numerical predictions divided by all scheduled versions, including unresolved outputs and failures.',
                'needs_review' => 'Abstention; counts against coverage and completion-adjusted accuracy, never a sixth class or directional triage error.',
                'upstream_failure' => 'B was not evaluated because A supplied no accepted facts; reported separately from a B-engine technical failure.',
                'consistency' => 'All five versions must have the same numerical prediction; any unresolved output fails the group.',
                'correct_consistency' => 'All five versions must be numerical and match the reference; denominator is all canonical cases in the run.',
                'runtime' => 'Milliseconds of recorded wall time. Shared stage includes requests, retries, validation and persistence up to the A result. Incremental B stage includes rule evaluation and persistence. Missing measurements are excluded and counted; no CPU time or independent second LLM request is implied.',
                'repeatability' => 'All three runs of a variant must have the same numerical prediction.',
                'mean' => 'Arithmetic mean of the three run rates; null if any run rate is not estimable.',
                'independence' => 'Repeated runs and language versions are clustered within canonical cases, not independent patients.']];
    }

    public function summarize(array $rows): array
    {
        $total = count($rows);
        $paired = array_values(array_filter($rows, fn (array $row): bool => $row['a_status'] === 'classified' && $row['b_status'] === 'classified'));
        $result = ['total' => $total, 'common_pair_count' => count($paired), 'common_pair_coverage' => $this->ratio(count($paired), $total)];
        $scheduledSupport = array_replace(array_fill(1, 5, 0), array_count_values(array_column($rows, 'reference')));
        $result['scheduled_support_by_esi'] = $scheduledSupport;
        foreach (['a', 'b'] as $method) {
            $counts = array_count_values(array_column($rows, $method.'_status'));
            $correct = count(array_filter($rows, fn (array $row): bool => $row[$method.'_status'] === 'classified' && $row[$method.'_priority'] === $row['reference']));
            $result['methods'][$method] = ['classified' => $counts['classified'] ?? 0,
                'needs_review' => $counts['needs_review'] ?? 0, 'technical_failures' => $counts['technical_failure'] ?? 0,
                'upstream_failures' => $counts['upstream_failure'] ?? 0, 'correct' => $correct,
                'coverage' => $this->ratio($counts['classified'] ?? 0, $total),
                'needs_review_rate' => $this->ratio($counts['needs_review'] ?? 0, $total),
                'technical_failure_rate' => $this->ratio($counts['technical_failure'] ?? 0, $total),
                'upstream_failure_rate' => $this->ratio($counts['upstream_failure'] ?? 0, $total),
                'completion_adjusted_accuracy' => $this->ratio($correct, $total)];
            foreach (range(1, 5) as $level) {
                $classCorrect = count(array_filter($rows, fn (array $row): bool => $row['reference'] === $level
                    && $row[$method.'_status'] === 'classified' && $row[$method.'_priority'] === $level));
                $result['methods'][$method]['completion_adjusted_recall_by_esi'][$level] = $this->ratio($classCorrect, $scheduledSupport[$level]);
            }
            $matrix = array_fill(1, 5, array_fill(1, 5, 0));
            $under = $over = $exact = $severe = 0;
            foreach ($paired as $row) {
                $prediction = $row[$method.'_priority'];
                $reference = $row['reference'];
                $matrix[$reference][$prediction]++;
                $under += (int) ($prediction > $reference);
                $severe += (int) ($prediction - $reference >= 2);
                $over += (int) ($prediction < $reference);
                $exact += (int) ($prediction === $reference);
            }
            $f1 = [];
            $recall = $support = [];
            foreach (range(1, 5) as $level) {
                $tp = $matrix[$level][$level];
                $fn = array_sum($matrix[$level]) - $tp;
                $fp = array_sum(array_column($matrix, $level)) - $tp;
                $f1[$level] = $this->ratio(2 * $tp, 2 * $tp + $fp + $fn) ?? 0.0;
                $support[$level] = $tp + $fn;
                $recall[$level] = $this->ratio($tp, $support[$level]);
            }
            $result['common_pairs'][$method] = ['correct' => $exact, 'undertriage' => $under, 'overtriage' => $over,
                'accuracy' => $this->ratio($exact, count($paired)), 'misclassification_rate' => $this->ratio($under + $over, count($paired)),
                'undertriage_rate' => $this->ratio($under, count($paired)), 'overtriage_rate' => $this->ratio($over, count($paired)),
                'one_level_undertriage' => $under - $severe, 'severe_undertriage' => $severe,
                'one_level_undertriage_rate' => $this->ratio($under - $severe, count($paired)),
                'severe_undertriage_rate' => $this->ratio($severe, count($paired)),
                'recall_by_esi' => $recall, 'reference_support_by_esi' => $support,
                'macro_f1' => $paired === [] ? null : array_sum($f1) / 5, 'f1_by_esi' => $paired === [] ? null : $f1,
                'confusion_matrix_reference_rows_prediction_columns' => $matrix];
        }

        return $result;
    }

    private function consistent(array $rows, string $method): bool
    {
        return array_all($rows, fn (array $row): bool => $row[$method.'_status'] === 'classified')
            && count(array_unique(array_column($rows, $method.'_priority'))) === 1;
    }

    /** Report existing measurements without treating unrecorded or unevaluated stages as zero. */
    private function runtime(array $rows): array
    {
        $result = [];
        foreach (['shared_stage_ms', 'rule_stage_wall_ms'] as $field) {
            $eligible = array_values(array_filter($rows, fn (array $row): bool => $field === 'shared_stage_ms' || $row['b_status'] !== 'upstream_failure'));
            $values = array_values(array_filter(array_column($eligible, $field), fn (mixed $value): bool => (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0));
            sort($values, SORT_NUMERIC);
            $count = count($values);
            $middle = intdiv($count, 2);
            $result[$field] = ['measured' => $count, 'missing' => count($eligible) - $count,
                'not_applicable' => count($rows) - count($eligible),
                'mean' => $count === 0 ? null : array_sum($values) / $count,
                'median' => $count === 0 ? null : ($count % 2 === 0 ? ($values[$middle - 1] + $values[$middle]) / 2 : $values[$middle]),
                'min' => $values[0] ?? null, 'max' => $count === 0 ? null : $values[$count - 1]];
        }

        return $result;
    }

    private function ratio(int $numerator, int $denominator): ?float
    {
        return $denominator === 0 ? null : $numerator / $denominator;
    }

    private function mean(array $values): ?float
    {
        return count($values) !== 3 || in_array(null, $values, true) ? null : array_sum($values) / 3;
    }
}
