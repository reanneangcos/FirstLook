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
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], array_unique(array_column($rules, 'priority')));
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
        config(['triage.api_key' => 'mock-only']);
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
}
