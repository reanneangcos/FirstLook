<?php

namespace Tests\Unit;

use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\EsiV4RuleLayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EsiV4RuleLayerTest extends TestCase
{
    /** Fictional software fixtures only; these approvals are never application configuration. */
    private function engine(?array $approvals = null): EsiV4RuleLayer
    {
        if ($approvals === null) {
            $approvals = [];
            foreach (array_keys((new EsiV4RuleCatalog)->rules()) as $id) {
                $approvals[$id] = ['status' => 'approved', 'reviewer' => 'FICTIONAL TEST REVIEWER',
                    'reviewed_at' => '2026-10-06', 'record_id' => 'TEST-ONLY', 'rule_version' => EsiV4RuleCatalog::VERSION];
            }
        }

        return new EsiV4RuleLayer(new EsiV4RuleCatalog($approvals));
    }

    private function facts(): array
    {
        return [['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34']];
    }

    private function observation(bool|int|float $value, ?string $unit = null): array
    {
        return ['state' => 'reported', 'value' => $value, 'evidence' => 'FICTIONAL explicit assessment record',
            'source' => $unit === null ? 'clinical_assessment' : 'measured', 'unit' => $unit,
            'recorded_by' => 'TEST-ONLY clinician', 'recorded_at' => '2026-10-06T08:00:00+08:00', 'record_id' => 'TEST-ONLY'];
    }

    private function assessment(int $resources = 2): array
    {
        return [
            'immediate_lifesaving_intervention_required' => $this->observation(false),
            'high_risk_situation' => $this->observation(false),
            'acute_confusion_lethargy_disorientation' => $this->observation(false),
            'severe_pain_or_distress' => $this->observation(false),
            'expected_esi_resources' => $this->observation($resources),
            'heart_rate' => $this->observation(100, 'beats/min'),
            'respiratory_rate' => $this->observation(20, 'breaths/min'),
            'oxygen_saturation' => $this->observation(92, '%'),
        ];
    }

    public function test_decision_a_takes_precedence_over_all_later_decisions(): void
    {
        $result = $this->engine()->evaluate($this->facts(), ['immediate_lifesaving_intervention_required' => $this->observation(true)]);

        $this->assertSame(1, $result['priority']);
        $this->assertSame('classified', $result['status']);
        $this->assertSame(['ESI4-A'], array_column($result['trace'], 'rule_id'));
        $this->assertSame(['ESI4-A'], $result['matched_rule_ids']);
    }

    public static function unavailableStates(): array
    {
        return ['missing' => [null, 'missing'], 'unknown' => [['state' => 'unknown', 'value' => null], 'unknown'],
            'not applicable' => [['state' => 'not_applicable', 'value' => null], 'not_applicable'],
            'conflicting' => [['state' => 'contradictory', 'value' => null], 'contradictory'],
            'absent is not an assessment' => [['state' => 'absent', 'value' => null], 'invalid_state']];
    }

    #[DataProvider('unavailableStates')]
    public function test_unavailable_decision_a_never_falls_through_to_b_or_low_priority(?array $observation, string $issue): void
    {
        $assessment = $this->assessment(0);
        unset($assessment['immediate_lifesaving_intervention_required']);
        if ($observation !== null) {
            $assessment['immediate_lifesaving_intervention_required'] = $observation;
        }
        $assessment['high_risk_situation'] = $this->observation(true);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame('A', $result['stopped_at']);
        $this->assertSame($issue, $result['trace'][0]['observations'][0]['issue']);
        $this->assertCount(1, $result['trace']);
    }

    public static function decisionBCriteria(): array
    {
        return [['high_risk_situation', 'ESI4-B-HIGH-RISK'],
            ['acute_confusion_lethargy_disorientation', 'ESI4-B-MENTAL-STATUS'],
            ['severe_pain_or_distress', 'ESI4-B-PAIN-DISTRESS']];
    }

    #[DataProvider('decisionBCriteria')]
    public function test_an_approved_positive_b_criterion_assigns_two_without_resource_or_vital_requirements(string $field, string $id): void
    {
        $assessment = ['immediate_lifesaving_intervention_required' => $this->observation(false), $field => $this->observation(true)];

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertSame(2, $result['priority']);
        $this->assertSame('B', $result['stopped_at']);
        $this->assertContains($id, $result['matched_rule_ids']);
    }

    public function test_a_pain_score_and_chronic_confusion_do_not_establish_decision_b(): void
    {
        $facts = [...$this->facts(),
            ['field' => 'reported_severity', 'state' => 'reported', 'value' => '10/10', 'evidence' => '10/10'],
            ['field' => 'known_conditions', 'state' => 'reported', 'value' => 'Chronic confusion', 'evidence' => 'Chronic confusion']];
        $assessment = $this->assessment(0);
        unset($assessment['severe_pain_or_distress'], $assessment['acute_confusion_lethargy_disorientation']);

        $result = $this->engine()->evaluate($facts, $assessment);

        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['priority']);
        $this->assertSame('B', $result['stopped_at']);
    }

    public static function resourceCounts(): array
    {
        return ['zero' => [0, 5, 'C'], 'one' => [1, 4, 'C'], 'two' => [2, 3, 'D'], 'many' => [7, 3, 'D']];
    }

    #[DataProvider('resourceCounts')]
    public function test_resource_branches_follow_explicit_negative_a_and_b(int $resources, int $priority, string $point): void
    {
        $assessment = $this->assessment($resources);
        if ($resources < 2) {
            unset($assessment['heart_rate'], $assessment['respiratory_rate'], $assessment['oxygen_saturation']);
        }

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertSame($priority, $result['priority']);
        $this->assertSame($point, $result['stopped_at']);
    }

    public function test_tests_and_devices_never_become_expected_resources(): void
    {
        $facts = $this->facts();
        foreach (['tests_completed' => 'CBC, urinalysis', 'tests_requested' => 'X-ray, CT scan', 'medical_devices' => 'Catheter'] as $field => $value) {
            $facts[] = ['field' => $field, 'state' => 'reported', 'value' => $value, 'evidence' => $value];
        }
        $assessment = $this->assessment();
        unset($assessment['expected_esi_resources']);

        $result = $this->engine()->evaluate($facts, $assessment);

        $this->assertSame('C', $result['stopped_at']);
        $this->assertNull($result['priority']);
    }

    public static function dangerZones(): array
    {
        return ['HR above' => ['heart_rate', 101, 'beats/min'], 'HR fractional' => ['heart_rate', 100.1, 'beats/min'],
            'RR above' => ['respiratory_rate', 21, 'breaths/min'], 'RR fractional' => ['respiratory_rate', 20.1, 'breaths/min'],
            'oxygen below' => ['oxygen_saturation', 91.9, '%']];
    }

    #[DataProvider('dangerZones')]
    public function test_danger_zone_values_flag_clinical_judgment_without_assigning_two(string $field, int|float $value, string $unit): void
    {
        $assessment = $this->assessment();
        $assessment[$field] = $this->observation($value, $unit);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['priority']);
        $this->assertSame(['consider_esi_2'], $result['flags']);
        $this->assertSame('Consider ESI 2 — clinical judgment required.', $result['explanation']);
        $this->assertSame([$field], $result['trace'][5]['danger_zone_fields']);
    }

    public function test_exact_thresholds_do_not_trigger_danger_zone_and_trace_is_auditable(): void
    {
        $result = $this->engine()->evaluate($this->facts(), $this->assessment());

        $this->assertSame(3, $result['priority']);
        $this->assertSame([], $result['flags']);
        $this->assertSame(['A', 'B', 'B', 'B', 'C', 'D'], array_column($result['trace'], 'decision_point'));
        foreach ($result['trace'] as $entry) {
            $this->assertSame(EsiV4RuleCatalog::ALGORITHM, $entry['source']['algorithm']);
            $this->assertSame('approved', $entry['reviewer_approval']['status']);
            $this->assertSame($result['rule_version'], $entry['rule_version']);
            $this->assertNotEmpty($entry['required_fields']);
            $this->assertNotEmpty($entry['matched_evidence']);
        }
    }

    public static function missingVitals(): array
    {
        return [['heart_rate'], ['respiratory_rate'], ['oxygen_saturation']];
    }

    #[DataProvider('missingVitals')]
    public function test_no_esi_three_when_any_required_vital_is_missing(string $field): void
    {
        $assessment = $this->assessment();
        unset($assessment[$field]);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertSame('D', $result['stopped_at']);
        $this->assertNull($result['priority']);
        $this->assertContains($field.':missing', $result['trace'][5]['blocking_reasons']);
    }

    public function test_a_known_danger_zone_is_flagged_even_when_another_vital_is_unknown(): void
    {
        $assessment = $this->assessment();
        $assessment['heart_rate'] = $this->observation(110, 'beats/min');
        unset($assessment['oxygen_saturation']);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame(['consider_esi_2'], $result['flags']);
    }

    public function test_pending_and_stale_approvals_cannot_classify_or_allow_continuation(): void
    {
        $result = $this->engine([])->evaluate($this->facts(), $this->assessment(0));
        $this->assertNull($result['priority']);
        $this->assertContains('rule_not_approved', $result['trace'][0]['blocking_reasons']);

        $approval = ['status' => 'approved', 'reviewer' => 'TEST', 'reviewed_at' => '2026-10-06', 'record_id' => 'TEST', 'rule_version' => 'obsolete'];
        $stale = $this->engine(['ESI4-A' => $approval])->evaluate($this->facts(), ['immediate_lifesaving_intervention_required' => $this->observation(true)]);
        $this->assertNull($stale['priority']);
        $this->assertFalse($stale['trace'][0]['approved']);
    }

    public static function invalidObservations(): array
    {
        return [
            'boolean text' => ['immediate_lifesaving_intervention_required', ['value' => 'false'], 'A'],
            'LLM judgment' => ['high_risk_situation', ['source' => 'llm'], 'B'],
            'fractional resources' => ['expected_esi_resources', ['value' => 1.5], 'C'],
            'negative resources' => ['expected_esi_resources', ['value' => -1], 'C'],
            'string resources' => ['expected_esi_resources', ['value' => '0'], 'C'],
            'missing resource evidence' => ['expected_esi_resources', ['evidence' => ''], 'C'],
            'invalid assessment timestamp' => ['expected_esi_resources', ['recorded_at' => 'yesterday'], 'C'],
            'contradictory vital' => ['heart_rate', ['state' => 'contradictory'], 'D'],
            'unmeasured vital' => ['heart_rate', ['source' => 'patient_reported'], 'D'],
            'wrong unit' => ['respiratory_rate', ['unit' => 'per second'], 'D'],
            'impossible percentage' => ['oxygen_saturation', ['value' => 101], 'D'],
            'null value' => ['oxygen_saturation', ['value' => null], 'D'],
        ];
    }

    #[DataProvider('invalidObservations')]
    public function test_invalid_or_unverified_observations_require_review(string $field, array $changes, string $point): void
    {
        $assessment = $this->assessment();
        $assessment[$field] = array_replace($assessment[$field], $changes);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame($point, $result['stopped_at']);
    }

    public function test_results_are_deterministic_and_patient_facts_are_not_changed(): void
    {
        $facts = [...$this->facts(), ['field' => 'allergies', 'state' => 'unknown', 'value' => null, 'evidence' => null],
            ['field' => 'medical_devices', 'state' => 'not_applicable', 'value' => 'N/A', 'evidence' => 'N/A']];
        $before = $facts;
        $engine = $this->engine();
        $first = $engine->evaluate($facts, $this->assessment());
        $engine->evaluate($facts, ['immediate_lifesaving_intervention_required' => $this->observation(true)]);

        $this->assertSame($first, $engine->evaluate($facts, $this->assessment()));
        $this->assertSame($before, $facts);
    }

    public function test_pediatric_missing_or_conflicting_age_cannot_use_the_adult_engine(): void
    {
        $pediatric = [['field' => 'age', 'state' => 'reported', 'value' => '17', 'evidence' => '17']];
        foreach ([$pediatric, [], [...$this->facts(), ...$pediatric]] as $facts) {
            $result = $this->engine()->evaluate($facts, $this->assessment(0));
            $this->assertNull($result['priority']);
            $this->assertSame('scope', $result['stopped_at']);
        }
    }

    public function test_approval_changes_get_a_new_revision_but_key_order_does_not(): void
    {
        $approval = ['status' => 'approved', 'reviewer' => 'TEST', 'reviewed_at' => '2026-10-06',
            'record_id' => 'TEST-ONLY', 'rule_version' => EsiV4RuleCatalog::VERSION];
        $first = $this->engine(['ESI4-A' => $approval]);
        $reordered = $this->engine(['ESI4-A' => array_reverse($approval, true)]);

        $this->assertSame($first->version(), $reordered->version());
        $this->assertNotSame($first->version(), $this->engine([])->version());
        $this->assertNotSame($first->version(), $this->engine(['ESI4-A' => array_replace($approval, ['status' => 'revoked'])])->version());
    }
}
