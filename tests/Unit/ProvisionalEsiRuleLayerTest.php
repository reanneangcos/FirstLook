<?php

namespace Tests\Unit;

use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleLayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProvisionalEsiRuleLayerTest extends TestCase
{
    /** Software tokens exercise the engine; they are not patient criteria or clinical approvals. */
    private function definition(int $priority = 3): array
    {
        $point = match ($priority) {
            1 => 'A', 2 => 'B', default => 'C'
        };

        return ['decision_point' => $point, 'priority' => $priority,
            'conditions' => [$this->condition('main_complaint', 'TEST_SUPPORT')],
            'exclusions' => match ($point) {
                'A' => [],
                'B' => ['A' => [$this->condition('symptom_description', 'TEST_EXCLUSION_A')]],
                'C' => ['A' => [$this->condition('symptom_description', 'TEST_EXCLUSION_A')],
                    'B' => [$this->condition('other_symptoms', 'TEST_EXCLUSION_B', 'absent')]],
            },
            'handbook_section' => 'TEST ONLY source locator',
            'adaptation_rationale' => 'TEST ONLY; no clinical interpretation.',
            'resource_rationale' => $point === 'C' ? 'TEST ONLY; no clinical resource claim.' : null,
            'explanation' => 'TEST ONLY matched software fixture.'];
    }

    private function condition(string $field, string $value, string $state = 'reported'): array
    {
        return ['field' => $field, 'state' => $state, 'values' => [$value]];
    }

    private function facts(): array
    {
        return [
            ['field' => 'age', 'state' => 'reported', 'value' => '34', 'evidence' => '34'],
            ['field' => 'main_complaint', 'state' => 'reported', 'value' => 'TEST_SUPPORT', 'evidence' => 'TEST_SUPPORT'],
            ['field' => 'symptom_description', 'state' => 'reported', 'value' => 'TEST_EXCLUSION_A', 'evidence' => 'TEST_EXCLUSION_A'],
            ['field' => 'other_symptoms', 'state' => 'absent', 'value' => 'TEST_EXCLUSION_B', 'evidence' => 'TEST_EXCLUSION_B'],
        ];
    }

    private function approvals(array $definitions): array
    {
        $approvals = [];
        foreach ($definitions as $id => $definition) {
            $approvals[$id] = ['status' => 'approved', 'reviewer' => 'FICTIONAL TEST REVIEWER',
                'reviewed_at' => '2026-10-10', 'record_id' => 'TEST-ONLY',
                'rule_version' => ProvisionalEsiRuleCatalog::VERSION,
                'definition_hash' => ProvisionalEsiRuleCatalog::definitionHash($definition)];
        }

        return $approvals;
    }

    private function engine(?array $definitions = null, ?array $approvals = null): ProvisionalEsiRuleLayer
    {
        $definitions ??= ['TEST-C' => $this->definition()];

        return new ProvisionalEsiRuleLayer(new ProvisionalEsiRuleCatalog($definitions, $approvals ?? $this->approvals($definitions)));
    }

    public function test_provisional_three_requires_an_explicit_mapping_but_no_measured_vitals(): void
    {
        $result = $this->engine()->evaluate($this->facts());

        $this->assertSame(3, $result['priority']);
        $this->assertSame('classified', $result['status']);
        $this->assertSame('provisional_esi_prediction', $result['output_kind']);
        $this->assertSame('not_assessed', $result['decision_d']['status']);
        $this->assertSame(EsiV4RuleCatalog::PROVISIONAL_THREE_LABEL, $result['display_label']);
        $this->assertTrue($result['clinical_review_required']);
        $this->assertSame(['TEST-C'], $result['matched_rule_ids']);
        $this->assertSame(['TEST_SUPPORT', 'TEST_EXCLUSION_A', 'TEST_EXCLUSION_B'], $result['trace'][0]['matched_evidence']);
        $this->assertSame('approved', $result['trace'][0]['reviewer_approval']['status']);
        $this->assertSame(ProvisionalEsiRuleCatalog::DESIGN_SOURCES, $result['design_sources']);
        $this->assertArrayNotHasKey('expected_esi_resources', $result);
    }

    public function test_empty_catalog_and_unapproved_mapping_abstain(): void
    {
        $empty = $this->engine([])->evaluate($this->facts());
        $this->assertNull($empty['priority']);
        $this->assertSame('rule_configuration', $empty['stopped_at']);
        $pending = $this->engine(null, [])->evaluate($this->facts());
        $this->assertNull($pending['priority']);
        $this->assertContains('rule_not_approved_for_this_definition', $pending['trace'][0]['blocking_reasons']);
    }

    public static function provisionalLevels(): array
    {
        return [[1], [2], [3], [4], [5]];
    }

    #[DataProvider('provisionalLevels')]
    public function test_only_the_explicit_reviewed_level_is_returned(int $priority): void
    {
        $result = $this->engine(['TEST-LEVEL' => $this->definition($priority)])->evaluate($this->facts());

        $this->assertSame($priority, $result['priority']);
        $this->assertSame('classified', $result['status']);
        $this->assertSame('not_assessed', $result['decision_d']['status']);
    }

    public function test_ungrounded_required_evidence_blocks_classification_with_a_specific_reason(): void
    {
        $facts = $this->facts();
        $facts[1]['evidence'] = 'DIFFERENT_TEST_EVIDENCE';

        $result = $this->engine()->evaluate($facts);

        $this->assertNull($result['priority']);
        $this->assertContains('main_complaint:invalid_value_or_evidence', $result['trace'][0]['blocking_reasons']);
    }

    public function test_rule_ids_cannot_be_blank(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->engine(['' => $this->definition()])->evaluate($this->facts());
    }

    public static function unavailableFacts(): array
    {
        return [['missing'], ['unknown'], ['not_applicable'], ['contradictory']];
    }

    #[DataProvider('unavailableFacts')]
    public function test_missing_unknown_and_not_applicable_never_substitute_for_an_explicit_exclusion(string $state): void
    {
        $facts = $this->facts();
        if ($state === 'missing') {
            array_pop($facts);
        } else {
            $facts[3] = ['field' => 'other_symptoms', 'state' => $state, 'value' => null, 'evidence' => null];
        }
        $result = $this->engine()->evaluate($facts);

        $this->assertSame('needs_review', $result['status']);
        $this->assertNull($result['priority']);
        $this->assertSame($state, $result['trace'][0]['observations'][2]['state']);
    }

    public function test_reported_negative_and_not_applicable_remain_distinct(): void
    {
        $facts = $this->facts();
        $facts[3]['state'] = 'not_applicable';
        $result = $this->engine()->evaluate($facts);
        $this->assertNull($result['priority']);
        $this->assertSame('no_matching_rule', $result['stopped_at']);
    }

    public function test_decisions_a_then_b_precede_resource_mappings_regardless_of_catalog_order(): void
    {
        $definitions = ['TEST-C' => $this->definition(3), 'TEST-B' => $this->definition(2), 'TEST-A' => $this->definition(1)];
        $result = $this->engine($definitions)->evaluate($this->facts());
        $this->assertSame(1, $result['priority']);
        $this->assertSame(['TEST-A'], $result['matched_rule_ids']);
        unset($definitions['TEST-A']);
        $this->assertSame(2, $this->engine($definitions)->evaluate($this->facts())['priority']);
    }

    public function test_unresolved_earlier_rule_blocks_a_later_mapping(): void
    {
        $earlier = $this->definition(1);
        $earlier['conditions'] = [$this->condition('symptom_description', 'TEST_EXCLUSION_A'), $this->condition('worsening', 'TEST_WORSENING')];
        $result = $this->engine(['TEST-A' => $earlier, 'TEST-C' => $this->definition()])->evaluate($this->facts());
        $this->assertSame('A', $result['stopped_at']);
        $this->assertNull($result['priority']);
    }

    public function test_conflicting_resource_mappings_abstain_instead_of_arbitrarily_selecting_one(): void
    {
        $result = $this->engine(['TEST-C3' => $this->definition(3), 'TEST-C4' => $this->definition(4)])->evaluate($this->facts());
        $this->assertNull($result['priority']);
        $this->assertSame('needs_review', $result['status']);
        $this->assertCount(2, $result['matched_rule_ids']);
    }

    public function test_changed_condition_invalidates_approval_and_changes_saved_version(): void
    {
        $definitions = ['TEST-C' => $this->definition()];
        $approvals = $this->approvals($definitions);
        $original = $this->engine($definitions, $approvals);
        $definitions['TEST-C']['conditions'][0]['values'][] = 'ANOTHER_TEST_TOKEN';
        $changed = $this->engine($definitions, $approvals);
        $this->assertNotSame($original->version(), $changed->version());
        $this->assertNull($changed->evaluate($this->facts())['priority']);
        $this->assertSame($original->evaluate($this->facts()), $original->evaluate(array_reverse($this->facts())));
    }

    public function test_optional_history_does_not_block_a_supported_mapping(): void
    {
        $facts = [...$this->facts(), ['field' => 'allergies', 'state' => 'unknown', 'value' => null, 'evidence' => null]];
        $this->assertSame(3, $this->engine()->evaluate($facts)['priority']);
    }

    public function test_no_match_and_test_or_device_names_never_become_resource_estimates(): void
    {
        $facts = $this->facts();
        $facts[1]['value'] = $facts[1]['evidence'] = 'UNMATCHED_TEST_TOKEN';
        foreach (['tests_completed', 'tests_requested', 'medical_devices'] as $field) {
            $facts[] = ['field' => $field, 'state' => 'reported', 'value' => 'CBC, X-ray, catheter', 'evidence' => 'CBC, X-ray, catheter'];
        }
        $result = $this->engine()->evaluate($facts);
        $this->assertNull($result['priority']);
        $this->assertSame('no_matching_rule', $result['stopped_at']);
    }

    public function test_negated_or_longer_text_cannot_match_by_substring(): void
    {
        $facts = $this->facts();
        $facts[1]['value'] = $facts[1]['evidence'] = 'NO TEST_SUPPORT';
        $this->assertNull($this->engine()->evaluate($facts)['priority']);
    }

    public function test_duplicate_fields_and_clinical_observations_are_rejected(): void
    {
        $facts = [...$this->facts(), $this->facts()[1]];
        $this->assertSame('input', $this->engine()->evaluate($facts)['stopped_at']);
        $this->assertSame('scope', $this->engine()->evaluate($this->facts(), ['heart_rate' => ['value' => 80]])['stopped_at']);
    }

    public function test_a_lower_priority_mapping_without_explicit_earlier_exclusions_is_invalid(): void
    {
        $definition = $this->definition();
        $definition['exclusions'] = [];
        $this->expectException(\InvalidArgumentException::class);
        $this->engine(['TEST-C' => $definition])->evaluate($this->facts());
    }

    public function test_rules_cannot_use_an_llm_priority_or_vital_as_a_condition(): void
    {
        foreach (['method_a_priority', 'heart_rate', 'expected_esi_resources'] as $field) {
            $definition = $this->definition();
            $definition['conditions'][] = $this->condition($field, 'TEST');
            try {
                $this->engine(['TEST-C' => $definition])->evaluate($this->facts());
                $this->fail('Unsupported field was accepted: '.$field);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
