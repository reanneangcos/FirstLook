<?php

namespace Tests\Feature;

use App\Services\Screening\StudyProtocol;
use App\Services\TriageRules\DraftPatientMappings;
use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DraftPatientMappingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_drafts_have_sources_and_semantic_conditions_without_fabricated_approvals(): void
    {
        $rules = (new ProvisionalEsiRuleCatalog(DraftPatientMappings::definitions()))->rules();
        $this->assertCount(18, $rules);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], array_values(array_unique(array_column($rules, 'priority'))));
        foreach (DraftPatientMappings::proposals() as $id => $proposal) {
            $this->assertFalse($rules[$id]['approved']);
            $this->assertSame('pending_review', $rules[$id]['reviewer_approval']['status']);
            $this->assertNull($rules[$id]['reviewer_approval']['reviewer']);
            $this->assertSame('unvalidated_draft', $proposal['validation_status']);
            $this->assertSame(EsiV4RuleCatalog::HANDBOOK, $proposal['source_document']['url']);
            $this->assertSame('2012 Edition', $proposal['source_document']['edition']);
            $this->assertSame(DraftPatientMappings::SOURCE_SHA256, $proposal['source_document']['sha256']);
            $this->assertNotEmpty($proposal['unresolved_assessment']);
            $this->assertStringContainsString('UNVALIDATED', $rules[$id]['adaptation_rationale']);
            foreach ([$rules[$id]['conditions'], ...array_values($rules[$id]['exclusions'])] as $conditions) {
                foreach ($conditions as $condition) {
                    $this->assertSame(['concept', 'state'], array_keys($condition));
                    $this->assertArrayNotHasKey('values', $condition);
                }
            }
        }
        config(['triage.api_key' => 'mock-only', 'esi.provisional_approvals' => []]);
        $report = app(StudyProtocol::class)->readiness();
        $this->assertFalse($report['ready']);
        $this->assertSame('provisional_patient_report', $report['active_mode']);
        $this->assertSame(18, $report['rule_count']);
        $this->assertSame(0, $report['approved_rule_count']);
        Http::assertNothingSent();
    }

    public function test_preview_executes_all_draft_profiles_but_does_not_score_approve_or_write_them(): void
    {
        $this->assertSame(0, Artisan::call('esi:patient-preview', ['--json' => true]));
        $report = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertCount(24, $report['examples']);
        $this->assertFalse($report['writes_database']);
        $this->assertFalse($report['calls_llm']);
        $this->assertFalse($report['study_eligible']);
        foreach ($report['examples'] as $example) {
            $result = $example['result'];
            $this->assertNull($result['priority']);
            $this->assertFalse($result['study_eligible']);
            if (isset($report['proposals'][$example['id']])) {
                $this->assertSame($report['proposals'][$example['id']]['candidate_priority'], $result['candidate_priority'], $example['id']);
            } else {
                $this->assertNull($result['candidate_priority'], $example['id']);
            }
        }
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_review_report_shows_concepts_sources_and_blank_review_fields(): void
    {
        config(['esi.provisional_approvals' => []]);
        $this->assertSame(0, Artisan::call('esi:patient-preview', ['--markdown' => true]));
        $report = Artisan::output();
        $this->assertStringContainsString('Status: unvalidated proposals', $report);
        $this->assertStringContainsString('DRAFT-C-RIGHT-LOWER-ABDOMEN', $report);
        $this->assertStringContainsString('`right_lower_abdominal_pain`: **reported**', $report);
        $this->assertStringContainsString('Definition hash:', $report);
        $this->assertSame(18, substr_count($report, 'Review decision: __________'));
        $this->assertStringContainsString('do not cover every possible condition', $report);
        $this->assertStringNotContainsString('wording remain to be specified', $report);
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_recorded_verbal_approval_preserves_user_report_provenance_without_claiming_a_signature(): void
    {
        config(['triage.api_key' => 'mock-only']);
        $protocol = app(StudyProtocol::class);
        $this->assertTrue($protocol->readiness()['ready']);
        $this->assertSame(18, $protocol->readiness()['approved_rule_count']);
        foreach ($protocol->catalog()->rules() as $rule) {
            $approval = $rule['reviewer_approval'];
            $this->assertTrue($rule['approved']);
            $this->assertSame('verbal', $approval['approval_method']);
            $this->assertSame('user_report', $approval['evidence_type']);
            $this->assertFalse($approval['direct_reviewer_confirmation']);
            $this->assertNull($approval['signed_record_reference']);
            $this->assertSame('2026-10-20', $approval['reviewed_at']);
        }
        $this->assertSame(0, Artisan::call('esi:patient-preview', ['--markdown' => true]));
        $report = Artisan::output();
        $this->assertStringContainsString('18 of 18 configured rules have current approval records', $report);
        $this->assertStringContainsString('Abigail Rose Gayatin-Sabandal, PHRN, USRN', $report);
        $this->assertStringContainsString('verbal; evidence: user_report', $report);
        $this->assertStringNotContainsString('No clinical approvals have been recorded', $report);
        $this->assertStringNotContainsString('Abigail', $protocol->prompt());
        $this->assertDatabaseCount('screening_sessions', 0);
        Http::assertNothingSent();
    }

    public function test_reported_approval_does_not_extend_to_changed_or_new_definitions(): void
    {
        config(['triage.api_key' => 'mock-only']);
        $definition = config('esi.provisional_rules.DRAFT-C-REFILL');
        $definition['explanation'] .= ' Changed only in this test.';
        config(['esi.provisional_rules.DRAFT-C-REFILL' => $definition,
            'esi.provisional_rules.TEST-ADDED-AFTER-REVIEW' => $definition]);
        $protocol = app(StudyProtocol::class);
        $rules = $protocol->catalog()->rules();
        $this->assertFalse($rules['DRAFT-C-REFILL']['approved']);
        $this->assertFalse($rules['TEST-ADDED-AFTER-REVIEW']['approved']);
        $this->assertSame('pending_review', $rules['TEST-ADDED-AFTER-REVIEW']['reviewer_approval']['status']);
        $this->assertFalse($protocol->readiness()['ready']);
        $this->assertSame(17, $protocol->readiness()['approved_rule_count']);
        Http::assertNothingSent();
    }
}
