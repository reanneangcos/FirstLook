<?php

namespace App\Services\TriageRules;

use App\Services\Screening\NormalizedPatientFacts;
use App\Services\Screening\PatientFields;

final class ProvisionalEsiRuleCatalog
{
    public const VERSION = 'provisional-esi-patient-report-2.0.0';

    public const DESIGN_SOURCES = [
        'gaber_2025' => 'https://doi.org/10.1038/s41746-025-01684-1',
        'sharma_2026' => 'https://doi.org/10.1016/j.ijmedinf.2026.106652',
        'elias_2015' => 'https://doi.org/10.2196/medinform.3508',
    ];

    /** @param array<string, array<string, mixed>> $definitions @param array<string, array<string, mixed>> $approvals */
    public function __construct(private readonly array $definitions = [], private readonly array $approvals = []) {}

    /** The full definition and approval content is part of the persisted experiment version. */
    public function version(): string
    {
        return self::VERSION.'+'.substr(self::fingerprint([$this->definitions, $this->approvals, NormalizedPatientFacts::reviewedDefinitions()]), 0, 16);
    }

    /** @param array<string, mixed> $definition */
    public static function definitionHash(array $definition): string
    {
        return self::fingerprint([$definition, NormalizedPatientFacts::reviewedDefinitions()]);
    }

    /** @return array<string, array<string, mixed>> */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->definitions as $id => $definition) {
            if (! $this->hasText($id) || ! is_array($definition) || ! $this->validDefinition($definition)) {
                throw new \InvalidArgumentException('Invalid provisional rule definition.');
            }
            $approval = $this->approvals[$id] ?? [];
            $approval = array_replace([
                'status' => 'pending_review', 'reviewer' => null, 'reviewed_at' => null,
                'record_id' => null, 'rule_version' => null, 'definition_hash' => null,
            ], is_array($approval) ? $approval : []);
            $conditions = $definition['conditions'];
            foreach ($definition['exclusions'] as $exclusion) {
                $conditions = [...$conditions, ...$exclusion];
            }
            $rules[$id] = $definition + [
                'rule_id' => $id,
                'condition' => 'All supporting conditions and earlier-decision exclusions must match their reviewed values and states.',
                'required_fields' => array_values(array_unique(array_column($conditions, 'field'))),
                'required_concepts' => array_values(array_unique(array_column($conditions, 'concept'))),
                'source' => ['algorithm' => EsiV4RuleCatalog::ALGORITHM, 'handbook' => EsiV4RuleCatalog::HANDBOOK, 'section' => $definition['handbook_section']],
                'reviewer_approval' => $approval,
                'approved' => $approval['status'] === 'approved'
                    && $approval['rule_version'] === self::VERSION
                    && $approval['definition_hash'] === self::definitionHash($definition)
                    && $this->hasText($approval['reviewer']) && $this->hasText($approval['record_id'])
                    && $this->validDate($approval['reviewed_at']),
                'rule_version' => $this->version(),
            ];
        }
        ksort($rules);

        return $rules;
    }

    /** @param array<string, mixed> $definition */
    private function validDefinition(array $definition): bool
    {
        $keys = ['decision_point', 'priority', 'conditions', 'exclusions', 'handbook_section',
            'adaptation_rationale', 'resource_rationale', 'explanation'];
        if (array_diff(array_keys($definition), $keys) !== [] || array_diff($keys, array_keys($definition)) !== []) {
            return false;
        }
        $priorities = ['A' => [1], 'B' => [2], 'C' => [3, 4, 5]];
        $point = $definition['decision_point'];
        if (! is_string($point) || ! isset($priorities[$point]) || ! in_array($definition['priority'], $priorities[$point], true)
            || ! $this->validConditions($definition['conditions']) || ! is_array($definition['exclusions'])) {
            return false;
        }
        $exclusions = array_keys($definition['exclusions']);
        sort($exclusions);
        if ($exclusions !== match ($point) {
            'A' => [], 'B' => ['A'], 'C' => ['A', 'B']
        }) {
            return false;
        }
        foreach ($definition['exclusions'] as $conditions) {
            if (! $this->validConditions($conditions)) {
                return false;
            }
        }
        foreach (['handbook_section', 'adaptation_rationale', 'explanation'] as $key) {
            if (! $this->hasText($definition[$key])) {
                return false;
            }
        }

        return ($point === 'C' ? $this->hasText($definition['resource_rationale']) : $definition['resource_rationale'] === null)
            && (array_column($definition['conditions'], 'concept') !== []
                || array_intersect(array_column($definition['conditions'], 'field'), ['main_complaint', 'symptom_description', 'other_symptoms']) !== []);
    }

    private function validConditions(mixed $conditions): bool
    {
        if (! is_array($conditions) || ! array_is_list($conditions) || $conditions === []) {
            return false;
        }
        foreach ($conditions as $condition) {
            if (is_array($condition) && array_key_exists('concept', $condition)) {
                if (count($condition) !== 2 || ! is_string($condition['concept'])
                    || ! isset(NormalizedPatientFacts::definitions()[$condition['concept']])
                    || ! in_array($condition['state'] ?? null, ['reported', 'absent', 'not_applicable'], true)) {
                    return false;
                }

                continue;
            }
            if (! is_array($condition) || count($condition) !== 3
                || ! in_array($condition['field'] ?? null, PatientFields::NAMES, true)
                || ! in_array($condition['state'] ?? null, ['reported', 'absent', 'not_applicable'], true)
                || ! is_array($condition['values'] ?? null) || ! array_is_list($condition['values']) || $condition['values'] === []
                || array_any($condition['values'], fn (mixed $value): bool => ! $this->hasText($value))) {
                return false;
            }
        }

        return true;
    }

    private function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function fingerprint(array $value): string
    {
        $normalize = function (array $items) use (&$normalize): array {
            if (! array_is_list($items)) {
                ksort($items);
            }
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $normalize($item);
                }
            }

            return $items;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR));
    }
}
