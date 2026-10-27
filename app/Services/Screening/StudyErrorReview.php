<?php

namespace App\Services\Screening;

use App\Models\ScreeningSession;

final class StudyErrorReview
{
    public const CATEGORIES = ['extraction_error', 'rule_matching_error', 'unsupported_or_unresolved', 'reference_dispute', 'technical_failure', 'other'];

    /** Numerical discrepancies select cases for human review; they do not establish the cause. */
    public function row(ScreeningSession $session, array $observation): ?array
    {
        $flags = [];
        foreach (['a', 'b'] as $method) {
            $status = $observation[$method.'_status'];
            if ($status !== 'classified') {
                $flags[] = $method.':'.$status;

                continue;
            }
            $difference = $observation[$method.'_priority'] - $observation['reference'];
            if ($difference !== 0) {
                $flags[] = $method.':'.($difference < 0 ? 'overtriage' : ($difference >= 2 ? 'severe_undertriage' : 'one_level_undertriage'));
            }
            if ($observation['reference'] <= 2 && $observation[$method.'_priority'] >= 3) {
                $flags[] = $method.':missed_high_acuity';
            }
            if ($observation['reference'] >= 3 && $observation[$method.'_priority'] <= 2) {
                $flags[] = $method.':false_high_acuity';
            }
        }
        if ($observation['a_status'] !== $observation['b_status'] || $observation['a_priority'] !== $observation['b_priority']) {
            $flags[] = 'method_disagreement';
        }
        if ($flags === []) {
            return null;
        }

        return $observation + ['selection_flags' => $flags, 'original_input' => $session->original_input,
            'extracted_facts' => $session->parsed_output['extracted_facts'] ?? [],
            'normalized_facts' => $session->parsed_output['normalized_facts'] ?? [],
            'missing_information' => $session->parsed_output['missing_information'] ?? [],
            'a_explanation' => $session->parsed_output['explanation'] ?? null,
            'b_matched_rule_ids' => $session->method_b_result['matched_rule_ids'] ?? [],
            'b_trace' => $session->method_b_result['trace'] ?? [],
            'b_explanation' => $session->method_b_result['explanation'] ?? null,
            'human_error_category' => '', 'human_review_notes' => '', 'reviewer' => '', 'reviewed_at' => ''];
    }

    /** @param list<array<string, mixed>> $rows */
    public function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        if ($rows !== []) {
            fputcsv($stream, array_keys($rows[0]), escape: '');
        }
        foreach ($rows as $row) {
            $this->writeCsvRow($stream, $row);
        }
        rewind($stream);
        $text = stream_get_contents($stream);
        fclose($stream);

        return $text;
    }

    /** @param resource $stream */
    public function writeCsvRow(mixed $stream, array $row): void
    {
        $values = array_map(function (mixed $value): mixed {
            if (is_array($value)) {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            }

            return is_string($value) && preg_match('/^[\s\x00-\x20]*[=+\-@]/u', $value) ? "'".$value : $value;
        }, $row);
        fputcsv($stream, $values, escape: '');
    }
}
