<?php

namespace Tests\Unit;

use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\EsiV4RuleLayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HandbookPreScreeningTest extends TestCase
{
    private function facts(): array
    {
        return [['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34']];
    }

    /** Fictional assessments exercise the decision table; never clinical approval records. */
    private function observation(bool|int|array $value): array
    {
        return ['state' => 'reported', 'value' => $value, 'source' => 'clinical_assessment',
            'evidence' => 'FICTIONAL explicit criterion', 'recorded_by' => 'TEST ONLY',
            'recorded_at' => '2026-10-11T00:00:00+08:00', 'record_id' => 'TEST ONLY'];
    }

    private function prerequisites(int $resources = 2): array
    {
        return ['immediate_lifesaving_intervention_required' => $this->observation(false),
            'high_risk_situation' => $this->observation(false),
            'acute_confusion_lethargy_disorientation' => $this->observation(false),
            'severe_pain_or_distress' => $this->observation(false),
            'expected_esi_resources' => $this->observation($resources)];
    }

    private function approvals(): array
    {
        return array_fill_keys(array_keys((new EsiV4RuleCatalog(deferVitalSigns: true))->rules()),
            ['status' => 'approved', 'reviewer' => 'FICTIONAL TEST REVIEWER', 'reviewed_at' => '2026-10-11',
                'record_id' => 'TEST ONLY', 'rule_version' => EsiV4RuleCatalog::VERSION]);
    }

    private function engine(?array $approvals = null): EsiV4RuleLayer
    {
        return new EsiV4RuleLayer(new EsiV4RuleCatalog($approvals ?? $this->approvals(), deferVitalSigns: true));
    }

    public function test_provisional_three_has_a_numeric_prediction_and_explicit_unassessed_vitals(): void
    {
        $result = $this->engine()->evaluate($this->facts(), $this->prerequisites());

        $this->assertSame('classified', $result['status']);
        $this->assertSame(3, $result['priority']);
        $this->assertSame(EsiV4RuleCatalog::PROVISIONAL_THREE_LABEL, $result['display_label']);
        $this->assertTrue($result['clinical_review_required']);
        $this->assertSame('not_assessed', $result['decision_d']['status']);
        $this->assertContains('ESI4-D-PROVISIONAL', $result['matched_rule_ids']);
        $this->assertTrue($result['trace'][5]['is_study_adaptation']);
        $this->assertCount(5, $result['trace'][5]['matched_evidence']);
        $this->assertStringNotContainsString('Wait for', $result['display_label']);
    }

    public static function earlierMissing(): array
    {
        return [['immediate_lifesaving_intervention_required', 'A'], ['high_risk_situation', 'B'],
            ['acute_confusion_lethargy_disorientation', 'B'], ['severe_pain_or_distress', 'B'], ['expected_esi_resources', 'C']];
    }

    #[DataProvider('earlierMissing')]
    public function test_unclear_earlier_conditions_never_get_a_likely_three(string $field, string $point): void
    {
        foreach (['missing', 'unknown', 'not_applicable', 'contradictory'] as $state) {
            $assessment = $this->prerequisites();
            if ($state === 'missing') {
                unset($assessment[$field]);
            } else {
                $assessment[$field] = ['state' => $state, 'value' => null];
            }
            $result = $this->engine()->evaluate($this->facts(), $assessment);
            $this->assertSame('needs_review', $result['status']);
            $this->assertNull($result['priority']);
            $this->assertSame('Needs Review', $result['display_label']);
            $this->assertSame($point, $result['stopped_at']);
            $this->assertArrayNotHasKey('candidate_priority', $result);
        }
    }

    public static function resourceBranches(): array
    {
        return [[0, 5], [1, 4], [2, 3], [7, 3]];
    }

    #[DataProvider('resourceBranches')]
    public function test_resource_branches_are_explicit_and_preserve_order(int $count, int $level): void
    {
        $this->assertSame($level, $this->engine()->evaluate($this->facts(), $this->prerequisites($count))['priority']);
        $assessment = $this->prerequisites($count);
        $assessment['high_risk_situation'] = $this->observation(true);
        $this->assertSame(2, $this->engine()->evaluate($this->facts(), $assessment)['priority']);
        $assessment['immediate_lifesaving_intervention_required'] = $this->observation(true);
        $this->assertSame(1, $this->engine()->evaluate($this->facts(), $assessment)['priority']);
    }

    public function test_provisional_adaptation_requires_its_own_approval(): void
    {
        $approvals = $this->approvals();
        unset($approvals['ESI4-D-PROVISIONAL']);
        $result = $this->engine($approvals)->evaluate($this->facts(), $this->prerequisites());
        $this->assertNull($result['priority']);
        $this->assertSame('D', $result['stopped_at']);
        $this->assertContains('rule_not_approved', $result['trace'][5]['blocking_reasons']);
        $this->assertSame(5, $this->engine($approvals)->evaluate($this->facts(), $this->prerequisites(0))['priority']);
    }

    public function test_preview_retains_pending_approval_but_cannot_be_mistaken_for_an_accepted_study_prediction(): void
    {
        $engine = $this->engine([]);
        $result = $engine->preview($this->facts(), $this->prerequisites());
        $this->assertSame('draft_preview', $result['status']);
        $this->assertNull($result['priority']);
        $this->assertSame(3, $result['candidate_priority']);
        $this->assertSame('classified', $result['candidate_status']);
        $this->assertFalse($result['study_eligible']);
        foreach ($result['trace'] as $rule) {
            $this->assertFalse($rule['approved']);
            $this->assertSame('pending_review', $rule['reviewer_approval']['status']);
            $this->assertNull($rule['reviewer_approval']['reviewer']);
            $this->assertFalse($rule['approval_enforced']);
        }
        $this->assertNull($engine->evaluate($this->facts(), $this->prerequisites())['priority']);
        $this->assertSame($result, $engine->preview($this->facts(), $this->prerequisites()));
    }

    public function test_fictional_review_sources_and_llm_judgments_cannot_enter_normal_evaluation(): void
    {
        foreach (['review_vignette', 'llm', 'patient_reported'] as $source) {
            $assessment = $this->prerequisites();
            $assessment['expected_esi_resources']['source'] = $source;
            $this->assertNull($this->engine()->evaluate($this->facts(), $assessment)['priority']);
            $preview = $this->engine([])->preview($this->facts(), $assessment);
            $this->assertSame($source === 'review_vignette' ? 3 : null, $preview['candidate_priority']);
        }
    }

    public function test_pre_screening_rejects_vitals_and_standard_esi_still_requires_them(): void
    {
        $assessment = $this->prerequisites();
        $assessment['heart_rate'] = $this->observation(80);
        $this->assertSame('scope', $this->engine()->evaluate($this->facts(), $assessment)['stopped_at']);
        $clinicalApprovals = $this->approvals();
        $clinicalApprovals['ESI4-D'] = $clinicalApprovals['ESI4-A'];
        $clinical = new EsiV4RuleLayer(new EsiV4RuleCatalog($clinicalApprovals));
        $result = $clinical->evaluate($this->facts(), $this->prerequisites());
        $this->assertNull($result['priority']);
        $this->assertSame('D', $result['stopped_at']);
        $this->assertNotSame($clinical->version(), $this->engine()->version());
    }

    public function test_reported_test_lists_devices_pain_scores_and_method_a_labels_do_not_supply_missing_assessments(): void
    {
        $facts = $this->facts();
        foreach (['main_complaint' => 'Chest pain', 'reported_severity' => '10/10', 'tests_requested' => 'CBC and X-ray',
            'medical_devices' => 'Catheter', 'method_a_priority' => '3'] as $field => $value) {
            $facts[] = ['field' => $field, 'state' => 'reported', 'value' => $value, 'evidence' => $value];
        }
        $this->assertNull($this->engine()->evaluate($facts)['priority']);
        $this->assertNull($this->engine([])->preview($facts, [])['candidate_priority']);
        $assessment = $this->prerequisites();
        unset($assessment['expected_esi_resources']);
        $this->assertSame('C', $this->engine()->evaluate($facts, $assessment)['stopped_at']);
    }

    public function test_a_verified_immediate_intervention_establishes_one_and_retains_the_evidence(): void
    {
        $assessment = ['immediate_interventions_required' => $this->observation(['intubation'])];

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertSame(1, $result['priority']);
        $this->assertSame(['ESI4-A'], $result['matched_rule_ids']);
        $this->assertSame(['intubation'], $result['trace'][0]['observations'][0]['matched_interventions']);
        $this->assertSame('2012 Edition', $result['source_document']['edition']);
        $this->assertSame($result, $this->engine()->evaluate($this->facts(), $assessment));
    }

    public static function unsupportedInterventions(): array
    {
        return ['oxygen alone' => [['oxygen_administration']], 'monitor alone' => [['cardiac_monitor']],
            'IV access alone' => [['iv_access']], 'empty list' => [[]], 'invented token' => [['emergency_symptom']]];
    }

    #[DataProvider('unsupportedInterventions')]
    public function test_non_lifesaving_or_unsupported_interventions_cannot_establish_positive_or_negative_a(array $services): void
    {
        $assessment = $this->prerequisites(0);
        unset($assessment['immediate_lifesaving_intervention_required']);
        $assessment['immediate_interventions_required'] = $this->observation($services);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame('A', $result['stopped_at']);
    }

    public function test_an_explicit_negative_a_cannot_override_a_conflicting_lifesaving_requirement(): void
    {
        $assessment = $this->prerequisites(0);
        $assessment['immediate_interventions_required'] = $this->observation(['bag_mask_ventilation']);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame('contradictory_intervention_assessments', $result['trace'][0]['observations'][0]['issue']);
    }

    public function test_a_verified_resource_plan_produces_provisional_three_with_the_counting_trace(): void
    {
        $assessment = $this->prerequisites();
        unset($assessment['expected_esi_resources']);
        $assessment['expected_resource_plan'] = $this->observation([
            'complete' => false, 'items' => ['blood_laboratory_test', 'urine_laboratory_test', 'plain_xray'],
        ]);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertSame(3, $result['priority']);
        $this->assertSame('not_assessed', $result['decision_d']['status']);
        $calculation = $result['trace'][4]['observations'][0]['resource_calculation'];
        $this->assertSame(2, $calculation['count']);
        $this->assertSame(['laboratory' => 1, 'plain_xray' => 1], $calculation['groups']);
        $this->assertSame($result, $this->engine()->evaluate($this->facts(), $assessment));
    }

    public function test_conflicting_resource_count_and_plan_require_review(): void
    {
        $assessment = $this->prerequisites(0);
        $assessment['expected_resource_plan'] = $this->observation(['complete' => true, 'items' => ['blood_laboratory_test', 'plain_xray']]);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame('C', $result['stopped_at']);
        $this->assertSame('contradictory_resource_estimates', $result['trace'][4]['observations'][0]['issue']);
    }

    public static function untrustedStructuredInputs(): array
    {
        return [
            'LLM intervention' => ['immediate_interventions_required', ['intubation'], 'llm', 'A'],
            'patient intervention' => ['immediate_interventions_required', ['intubation'], 'patient_reported', 'A'],
            'LLM resources' => ['expected_resource_plan', ['complete' => true, 'items' => []], 'llm', 'C'],
            'patient resources' => ['expected_resource_plan', ['complete' => true, 'items' => []], 'patient_reported', 'C'],
        ];
    }

    #[DataProvider('untrustedStructuredInputs')]
    public function test_new_structured_inputs_still_require_verified_provenance(string $field, array $value, string $source, string $point): void
    {
        $assessment = $this->prerequisites(0);
        $assessment[$field] = array_replace($this->observation($value), ['source' => $source]);

        $result = $this->engine()->evaluate($this->facts(), $assessment);

        $this->assertNull($result['priority']);
        $this->assertSame($point, $result['stopped_at']);
    }
}
