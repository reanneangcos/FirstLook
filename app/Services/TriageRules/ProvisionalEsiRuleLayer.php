<?php

namespace App\Services\TriageRules;

use App\Services\Screening\InvalidModelOutput;
use App\Services\Screening\NormalizedPatientFacts;
use App\Services\Screening\PatientFields;

final class ProvisionalEsiRuleLayer implements RuleLayer
{
    public function __construct(private readonly ProvisionalEsiRuleCatalog $catalog) {}

    public function version(): string
    {
        return $this->catalog->version();
    }

    /** @param list<array<string, mixed>> $understoodFacts @param array<string, array<string, mixed>> $clinicalAssessment @return array<string, mixed> */
    public function evaluate(array $understoodFacts, array $clinicalAssessment = [], array $normalizedFacts = []): array
    {
        return $this->decide($understoodFacts, $clinicalAssessment, $normalizedFacts, false);
    }

    /** Review-only execution: never changes approval records or produces a scored priority. */
    public function preview(array $understoodFacts, array $normalizedFacts = []): array
    {
        $candidate = $this->decide($understoodFacts, [], $normalizedFacts, true);

        return array_replace($candidate, [
            'status' => 'draft_preview', 'priority' => null,
            'candidate_status' => $candidate['status'], 'candidate_priority' => $candidate['priority'],
            'candidate_label' => $candidate['display_label'],
            'display_label' => 'Unvalidated patient-mapping preview',
            'study_eligible' => false, 'approval_enforced' => false,
            'explanation' => 'Unvalidated proposal for fictional review only. '.$candidate['explanation'],
        ]);
    }

    /** @param list<array<string, mixed>> $understoodFacts @param array<string, mixed> $clinicalAssessment */
    private function decide(array $understoodFacts, array $clinicalAssessment, array $normalizedFacts, bool $preview): array
    {
        if ($clinicalAssessment !== []) {
            return $this->result([], null, 'Clinical observations are outside this patient-report prediction method.', 'scope');
        }
        $facts = [];
        foreach ($understoodFacts as $fact) {
            if (! is_array($fact) || ! in_array($fact['field'] ?? null, PatientFields::NAMES, true)
                || array_key_exists($fact['field'], $facts)) {
                return $this->result([], null, 'The extracted facts contain unsupported or duplicate fields.', 'input');
            }
            $facts[$fact['field']] = $fact;
        }
        try {
            (new NormalizedPatientFacts)->validate($normalizedFacts, $understoodFacts);
        } catch (InvalidModelOutput $error) {
            return $this->result([], null, 'Normalized patient facts failed validation: '.$error->failureCode.'.', 'input');
        }
        $concepts = array_column($normalizedFacts, null, 'concept');
        if (array_any($normalizedFacts, fn (array $fact): bool => $fact['state'] === 'contradictory')
            || $this->conflictingConcepts($concepts)) {
            $result = $this->result([], null, 'Reported concepts contain an unresolved contradiction. Clarify the original patient statements.', 'input');
            $result['conflicting_evidence'] = $normalizedFacts;

            return $result;
        }
        $age = $facts['age'] ?? [];
        if (($age['state'] ?? null) !== 'reported' || ! is_string($age['value'] ?? null)
            || ! ctype_digit($age['value']) || $age['value'] !== ($age['evidence'] ?? null) || (int) $age['value'] < 18) {
            return $this->result([], null, 'An explicit, unambiguous adult age is required by the current rule specification.', 'scope');
        }
        $rules = $this->catalog->rules();
        if ($rules === []) {
            return $this->result([], null, 'No reviewed patient-report rule mappings are configured. Validated case labels and research articles do not specify these conditions.', 'rule_configuration');
        }

        $trace = [];
        foreach (['A', 'B', 'C'] as $point) {
            if ($point === 'C' && isset($concepts['unmapped_symptom'])
                && $concepts['unmapped_symptom']['state'] !== 'absent') {
                return $this->result($trace, null, 'An additional symptom has no supported interpretation in the current vocabulary.', 'C');
            }
            $matches = [];
            $blocked = false;
            foreach ($rules as $rule) {
                if ($rule['decision_point'] !== $point) {
                    continue;
                }
                $entry = $this->evaluateRule($rule, $facts, $concepts, $preview);
                $trace[] = $entry;
                if ($entry['outcome'] === 'matched') {
                    $matches[] = $rule;
                }
                $blocked = $blocked || $entry['outcome'] === 'blocked';
            }
            if ($blocked) {
                return $this->result($trace, null, 'Required facts or approval are unresolved for a potentially applicable rule at Decision '.$point.'.', $point);
            }
            if ($matches !== []) {
                $priorities = array_unique(array_column($matches, 'priority'));
                if (count($priorities) !== 1) {
                    return $this->result($trace, null, 'Applicable reviewed mappings disagree on the provisional priority.', $point);
                }

                $label = $matches[0]['priority'] === 3 ? EsiV4RuleCatalog::PROVISIONAL_THREE_LABEL.'. ' : '';

                return $this->result($trace, $matches[0]['priority'], $label.$matches[0]['explanation'].' This is a provisional prediction; Decision D was not assessed and clinical assessment remains pending.', $point);
            }
        }

        return $this->result($trace, null, 'No reviewed mapping supports a provisional priority from these facts. No-match is not an exclusion of clinical risk.', 'no_matching_rule');
    }

    /** @param array<string, mixed> $rule @param array<string, array<string, mixed>> $facts @return array<string, mixed> */
    private function evaluateRule(array $rule, array $facts, array $concepts, bool $preview): array
    {
        $observations = [];
        foreach (['support' => $rule['conditions'], ...$rule['exclusions']] as $purpose => $conditions) {
            foreach ($conditions as $condition) {
                if (isset($condition['concept'])) {
                    $concept = $concepts[$condition['concept']] ?? null;
                    $state = $concept['state'] ?? 'missing';
                    $available = in_array($state, ['reported', 'absent', 'not_applicable'], true);
                    $observations[] = ['concept' => $condition['concept'], 'purpose' => $purpose,
                        'state' => $state, 'evidence' => $concept['evidence'] ?? [],
                        'matches' => $available ? $state === $condition['state'] : null,
                        'issue' => $available ? null : $condition['concept'].':'.$state];

                    continue;
                }
                $fact = $facts[$condition['field']] ?? null;
                $state = $fact['state'] ?? 'missing';
                $value = $fact['value'] ?? null;
                $evidence = $fact['evidence'] ?? null;
                $available = in_array($state, ['reported', 'absent', 'not_applicable'], true)
                    && is_string($value) && trim($value) !== '' && $value === $evidence;
                $matches = $available ? $state === $condition['state'] && in_array($value, $condition['values'], true) : null;
                $issue = $available ? null : $condition['field'].':'.(
                    in_array($state, ['reported', 'absent', 'not_applicable'], true)
                        ? 'invalid_value_or_evidence' : (is_string($state) ? $state : 'invalid_state')
                );
                $observations[] = ['field' => $condition['field'], 'purpose' => $purpose,
                    'state' => $state, 'value' => $value, 'evidence' => $evidence,
                    'matches' => $matches, 'issue' => $issue];
            }
        }
        $outcome = array_any($observations, fn (array $item): bool => $item['matches'] === false) ? 'not_matched'
            : (array_any($observations, fn (array $item): bool => $item['matches'] === null) || (! $preview && ! $rule['approved']) ? 'blocked' : 'matched');
        /**
         * An unmentioned presentation is unresolved, not a negative assessment.
         * Later rules still require their own explicit A/B exclusions. A positively
         * reported presentation with incomplete context blocks later decisions.
         */
        if (isset($rule['conditions'][0]['concept']) && $observations[0]['matches'] === null) {
            $outcome = 'unresolved';
        }

        return $rule + [
            'outcome' => $outcome,
            'approval_enforced' => ! $preview,
            'action' => match ($outcome) {
                'matched' => 'provisional_esi_'.$rule['priority'], 'blocked', 'unresolved' => 'needs_review', default => 'no_match'
            },
            'observations' => $observations,
            'matched_evidence' => $outcome === 'matched' ? array_column($observations, 'evidence') : [],
            'blocking_reasons' => in_array($outcome, ['blocked', 'unresolved'], true) ? array_values(array_filter([
                ...($rule['approved'] || $preview ? [] : ['rule_not_approved_for_this_definition']),
                ...array_column($observations, 'issue'),
            ])) : [],
        ];
    }

    /** Explicit semantic incompatibilities; no clinical severity or resource inference. */
    private function conflictingConcepts(array $concepts): bool
    {
        $pairs = [
            ['breathing_without_help', 'breathing'],
            ['responding_normally', 'responds_to_voice'],
            ['chest_pressure', 'chest_discomfort'],
            ['right_lower_abdominal_pain', 'abdominal_pain'],
            ['worst_ever_headache', 'headache'],
            ['current_suicidal_intent', 'self_harm_thoughts'],
            ['cancer_history', 'known_medical_conditions'],
            ['hypertension_history', 'known_medical_conditions'],
        ];
        foreach ($pairs as [$specific, $general]) {
            if (($concepts[$specific]['state'] ?? null) === 'reported'
                && ($concepts[$general]['state'] ?? null) === 'absent') {
                return true;
            }
        }
        foreach (['severe_pain', 'moderate_pain', 'mild_pain_or_discomfort', 'headache', 'chest_pressure',
            'right_lower_abdominal_pain', 'one_sided_lower_leg_pain', 'pain_on_weight_bearing'] as $pain) {
            if (($concepts[$pain]['state'] ?? null) === 'reported'
                && ($concepts['pain_or_discomfort']['state'] ?? null) === 'absent') {
                return true;
            }
        }

        return false;
    }

    /** @param list<array<string, mixed>> $trace @return array<string, mixed> */
    private function result(array $trace, ?int $priority, string $explanation, string $stoppedAt): array
    {
        return [
            'status' => $priority === null ? 'needs_review' : 'classified', 'priority' => $priority,
            'rule_version' => $this->version(), 'trace' => $trace,
            'matched_rule_ids' => array_column(array_filter($trace, fn (array $entry): bool => $entry['outcome'] === 'matched'), 'rule_id'),
            'explanation' => $explanation, 'stopped_at' => $stoppedAt,
            'output_kind' => 'provisional_esi_prediction',
            'display_label' => $priority === 3 ? EsiV4RuleCatalog::PROVISIONAL_THREE_LABEL
                : ($priority === null ? 'Needs Review' : 'Provisional ESI '.$priority),
            'clinical_review_required' => true,
            'flags' => ['patient_report_only', 'decision_d_not_assessed', 'clinical_assessment_pending'],
            'decision_d' => ['status' => 'not_assessed', 'reason' => 'Measured vital signs are outside the study inputs.'],
            'clinical_source' => EsiV4RuleCatalog::HANDBOOK,
            'source_document' => EsiV4RuleCatalog::SOURCE_DOCUMENT,
            'normalization_version' => NormalizedPatientFacts::VERSION,
            'design_sources' => ProvisionalEsiRuleCatalog::DESIGN_SOURCES,
        ];
    }
}
