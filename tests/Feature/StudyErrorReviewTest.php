<?php

namespace Tests\Feature;

use App\Models\ScreeningSession;
use App\Services\Screening\StudyErrorReview;
use Tests\TestCase;

class StudyErrorReviewTest extends TestCase
{
    public function test_review_selects_errors_with_source_evidence_without_inventing_a_cause(): void
    {
        $session = ScreeningSession::factory()->make([
            'user_id' => null,
            'original_input' => ['main_complaint' => 'Fictional original report'],
            'parsed_output' => ['extracted_facts' => [['field' => 'age', 'state' => 'unknown']],
                'normalized_facts' => ['concepts' => []], 'explanation' => 'Fictional A explanation'],
            'method_b_result' => ['matched_rule_ids' => ['FIXTURE-RULE'], 'trace' => [['decision' => 'TEST']], 'explanation' => 'Fictional B explanation'],
        ]);
        $observation = ['reference' => 1, 'a_status' => 'classified', 'a_priority' => 3, 'b_status' => 'needs_review', 'b_priority' => null];

        $row = app(StudyErrorReview::class)->row($session, $observation);
        $this->assertSame(['a:severe_undertriage', 'a:missed_high_acuity', 'b:needs_review', 'method_disagreement'], $row['selection_flags']);
        $this->assertSame($session->original_input, $row['original_input']);
        $this->assertSame($session->parsed_output['extracted_facts'], $row['extracted_facts']);
        $this->assertSame(['FIXTURE-RULE'], $row['b_matched_rule_ids']);
        $this->assertSame('', $row['human_error_category']);
        $this->assertSame('', $row['reviewer']);
    }

    public function test_both_methods_being_correct_does_not_create_an_error_review(): void
    {
        $observation = ['reference' => 2, 'a_status' => 'classified', 'a_priority' => 2, 'b_status' => 'classified', 'b_priority' => 2];

        $this->assertNull(app(StudyErrorReview::class)->row(ScreeningSession::factory()->make(['user_id' => null]), $observation));
    }

    public function test_matching_wrong_answers_and_shared_failures_are_still_selected(): void
    {
        $service = app(StudyErrorReview::class);
        $session = ScreeningSession::factory()->make(['user_id' => null]);

        $wrong = $service->row($session, ['reference' => 4, 'a_status' => 'classified', 'a_priority' => 2, 'b_status' => 'classified', 'b_priority' => 2]);
        $this->assertSame(['a:overtriage', 'a:false_high_acuity', 'b:overtriage', 'b:false_high_acuity'], $wrong['selection_flags']);
        $failed = $service->row($session, ['reference' => 4, 'a_status' => 'technical_failure', 'a_priority' => null, 'b_status' => 'upstream_failure', 'b_priority' => null]);
        $this->assertContains('a:technical_failure', $failed['selection_flags']);
        $this->assertContains('b:upstream_failure', $failed['selection_flags']);
    }

    public function test_csv_preserves_unicode_and_nested_evidence_and_escapes_formula_prefixes(): void
    {
        $csv = app(StudyErrorReview::class)->csv([['explanation' => "\t=SUM(1,2)", 'evidence' => ['quote' => "Wala, sir\nwalay sakit"], 'priority' => 2]]);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream, escape: '');
        $values = fgetcsv($stream, escape: '');
        fclose($stream);

        $this->assertSame("'\t=SUM(1,2)", $values[0]);
        $this->assertSame(['quote' => "Wala, sir\nwalay sakit"], json_decode($values[1], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('2', $values[2]);
    }
}
