<?php

namespace App\Services\Screening;

use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class StudyStatistics
{
    public function __construct(private StudyMetrics $metrics) {}

    /** Resample canonical cases; never resample methods, languages or repetitions independently. */
    public function bootstrap(array $rows, int $resamples = 2000, int $seed = 42): array
    {
        if ($resamples < 1 || $rows === []) {
            throw new InvalidArgumentException('Bootstrap requires observations and a positive resample count.');
        }
        $this->metrics->calculate($rows);
        $groups = collect($rows)->groupBy('dataset_case_id')->sortKeys()->map(fn ($items): array => $items->groupBy('run')->map->all()->all())->values()->all();
        foreach ($groups as $group) {
            foreach ([1, 2, 3] as $run) {
                if (count($group[$run] ?? []) !== 5) {
                    throw new InvalidArgumentException('Keep all five versions and all three runs in each bootstrap group.');
                }
            }
        }
        $original = $this->differences($groups);
        $samples = array_fill_keys(array_keys($original), []);
        $rng = new Randomizer(new Mt19937($seed));
        for ($sample = 0; $sample < $resamples; $sample++) {
            $selected = [];
            for ($draw = 0; $draw < count($groups); $draw++) {
                $selected[] = $groups[$rng->getInt(0, count($groups) - 1)];
            }
            foreach ($this->differences($selected) as $metric => $difference) {
                if ($difference !== null) {
                    $samples[$metric][] = $difference;
                }
            }
        }
        $intervals = [];
        foreach ($samples as $metric => $values) {
            sort($values, SORT_NUMERIC);
            $intervals[$metric] = ['difference_b_minus_a' => $original[$metric],
                'lower_95' => $this->percentile($values, 0.025), 'upper_95' => $this->percentile($values, 0.975),
                'defined_resamples' => count($values), 'undefined_resamples' => $resamples - count($values)];
        }

        return ['resamples' => $resamples, 'seed' => $seed, 'canonical_groups' => count($groups),
            'random_generator' => 'PHP Randomizer / Mt19937', 'method' => 'Paired canonical-group percentile bootstrap; linear interpolated percentiles',
            'estimand' => 'Mean of the three per-run B-minus-A differences', 'intervals' => $intervals];
    }

    /** @param list<array<int, list<array<string, mixed>>>> $groups */
    private function differences(array $groups): array
    {
        $perRun = [];
        foreach ([1, 2, 3] as $run) {
            $rows = [];
            $consistent = ['a' => 0, 'b' => 0];
            foreach ($groups as $group) {
                array_push($rows, ...$group[$run]);
                foreach (['a', 'b'] as $method) {
                    $consistent[$method] += (int) (array_all($group[$run], fn (array $row): bool => $row[$method.'_status'] === 'classified')
                        && count(array_unique(array_column($group[$run], $method.'_priority'))) === 1);
                }
            }
            $summary = $this->metrics->summarize($rows);
            foreach (['accuracy', 'macro_f1', 'misclassification_rate', 'undertriage_rate', 'overtriage_rate'] as $metric) {
                $a = $summary['common_pairs']['a'][$metric];
                $b = $summary['common_pairs']['b'][$metric];
                $perRun[$metric][] = $a === null || $b === null ? null : $b - $a;
            }
            foreach (['coverage', 'needs_review_rate', 'technical_failure_rate', 'completion_adjusted_accuracy'] as $metric) {
                $perRun[$metric][] = $summary['methods']['b'][$metric] - $summary['methods']['a'][$metric];
            }
            $perRun['language_consistency'][] = ($consistent['b'] - $consistent['a']) / count($groups);
        }

        return array_map(fn (array $values): ?float => in_array(null, $values, true) ? null : array_sum($values) / 3, $perRun);
    }

    private function percentile(array $values, float $probability): ?float
    {
        if ($values === []) {
            return null;
        }
        $index = (count($values) - 1) * $probability;
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        return $values[$lower] + ($index - $lower) * ($values[$upper] - $values[$lower]);
    }

    /** The repetition is chosen in the freeze, before predictions are available. */
    public function mcnemar(array $rows, int $run, int $exactBelow = 25): array
    {
        $english = array_values(array_filter($rows, fn (array $row): bool => $row['run'] === $run && $row['language'] === 'English'));
        if (count(array_unique(array_column($english, 'dataset_case_id'))) !== count($english)) {
            throw new InvalidArgumentException('McNemar requires one English version per canonical case.');
        }
        $paired = array_values(array_filter($english, fn (array $row): bool => $row['a_status'] === 'classified' && $row['b_status'] === 'classified'));
        $b = $c = 0;
        foreach ($paired as $row) {
            $aCorrect = $row['a_priority'] === $row['reference'];
            $bCorrect = $row['b_priority'] === $row['reference'];
            $b += (int) ($aCorrect && ! $bCorrect);
            $c += (int) (! $aCorrect && $bCorrect);
        }
        $n = $b + $c;
        $chiSquare = $n === 0 ? 0.0 : (abs($b - $c) - 1) ** 2 / $n;
        $exact = $n < $exactBelow;
        if ($exact) {
            $term = 2.0 ** (-$n);
            $tail = $term;
            for ($k = 1; $k <= min($b, $c); $k++) {
                $term *= ($n - $k + 1) / $k;
                $tail += $term;
            }
            $pValue = min(1.0, 2 * $tail);
        } else {
            /** Chi-square(1) survival equals twice the standard-normal upper tail at sqrt(x). */
            $z = sqrt($chiSquare);
            $steps = 2000;
            $step = 12.0 / $steps;
            $integral = exp(-$z * $z / 2) + exp(-($z + 12) ** 2 / 2);
            for ($i = 1; $i < $steps; $i++) {
                $x = $z + $i * $step;
                $integral += ($i % 2 === 0 ? 2 : 4) * exp(-$x * $x / 2);
            }
            $pValue = max(0.0, min(1.0, 2 * $step * $integral / (3 * sqrt(2 * M_PI))));
        }

        return ['prespecified_run' => $run, 'language' => 'English', 'eligible_pairs' => count($paired),
            'excluded_unresolved' => count($english) - count($paired), 'a_correct_b_incorrect' => $b,
            'a_incorrect_b_correct' => $c, 'accuracy_difference_b_minus_a' => $paired === [] ? null : ($c - $b) / count($paired),
            'method' => $exact ? 'exact_two_sided_binomial' : 'continuity_corrected_chi_square_df_1',
            'chi_square' => $paired === [] ? null : $chiSquare, 'p_value' => $paired === [] ? null : $pValue,
            'alpha' => 0.05, 'exact_below_discordant' => $exactBelow,
            'note' => 'Secondary descriptive comparison on common valid English pairs. It does not establish clinical safety.'];
    }
}
