<?php

use App\Services\TriageRules\DraftPatientMappings;

/**
 * Internal record of verbal approval reported by the project user in chat.
 * The record ID is assigned by FirstLook; it is not an external signed document.
 * Literal hashes and version prevent this approval extending to later changes.
 */
$reportedReview = [
    'status' => 'approved',
    'reviewer' => 'Abigail Rose Gayatin-Sabandal, PHRN, USRN',
    'reviewed_at' => '2026-10-20',
    'record_id' => 'FIRSTLOOK-REPORTED-VERBAL-2026-10-20-01',
    'rule_version' => 'provisional-esi-patient-report-2.0.0',
    'approval_method' => 'verbal',
    'evidence_type' => 'user_report',
    'evidence_reference' => 'Project chat: current layer described as validated; reviewer identity, review date and verbal approval supplied by the user.',
    'scope_basis' => 'Current 18 patient-report definitions, including exclusions and provisional ESI 3, based on the user report about the current layer.',
    'reported_changes_or_exceptions' => null,
    'direct_reviewer_confirmation' => false,
    'signed_record_reference' => null,
];

$reviewedDefinitionHashes = [
    'DRAFT-A-APNEA' => '418dd36e9bebd0e46510a0fb1f3b672145c8bdba9d0e99ac4faddbe598d3559b',
    'DRAFT-B-ALLERGY' => 'daaee4eda4759a4112d3c7fa82c2e85f2a76404559e4bf8b3d39f7f45b623036',
    'DRAFT-B-CHEMOTHERAPY' => 'eefa8d1e88e37335d222daabae64bf877f87429cd4c13020b1ba0aa7bbe95317',
    'DRAFT-B-CHEST' => '1e64223eeb256331dd922e3b75e86bc70a56629f89f1101d8c6c157f25e4e013',
    'DRAFT-B-HEADACHE' => '387f2ea9065fc7ab86be62becca060f43cdaf25709a5881067421b41903f3e3f',
    'DRAFT-B-NEEDLESTICK' => '0fe0c9def4db1cbbab2bcfa1b45ca1038f8b5b52fd7205ceb65e594ad61c1df9',
    'DRAFT-B-NEUROLOGICAL' => '4551160e63732f3ba461e4b20daef8661903a80dc3c6ee7b715ffdc4d74e600e',
    'DRAFT-B-NEW-CONFUSION' => '964f82c57b7c8213893f9641882b5882bfa77209862ea8cfeefa2f2a0f42500b',
    'DRAFT-B-ONCOLOGY-PAIN' => '969f37a8214d5182c6dd8200866d584ff27dc85c9b34d3fb167c302f07ebad78',
    'DRAFT-B-OVERDOSE' => '38767e3a7b9576d92261942be6b0eba6cfa824a9163eee2a04daa078cdd816e0',
    'DRAFT-B-SELF-HARM' => '51e96e3389aad184739f2b8dd4b59a84ba9c19f7bcf707f8c7d3ce9dc9e71275',
    'DRAFT-C-ANKLE' => 'ced234a4d7b293bbd972e4e13faa4e8c8eb5ddd5d3a02e6c351fe9e92c9a2766',
    'DRAFT-C-FINGER-CUT' => 'b78ed44f105b9f8958e24809c8746397f0bc49435ce6c814413daf0b1d4d8cd9',
    'DRAFT-C-LEG-AFTER-TRAVEL' => '30c977c2b1eb1d32e272ac7be52f13d493e0a7c91586196bbd449cc37c2393c9',
    'DRAFT-C-REFILL' => 'b6b02bb1728e86ae6adb1f7abfb76257a028a3c815eafd7b4fc5178fdc96a0f3',
    'DRAFT-C-RIGHT-LOWER-ABDOMEN' => 'afb6a5a3b23386e5c09d63c454d05e93dfd7e6f313375b3ae6de96700838b00b',
    'DRAFT-C-URINARY' => '4bb4b7dcc5ffedee0eb6bb361b51621e05aaf7bbd9e625a0a6ca4eae2dda56c7',
    'DRAFT-C-WOUND-RECHECK' => '9ea785500dab2608168f93411074f3091a8aef74581af4ab9ed2b4a5ad57dcd6',
];

return [
    'mode' => env('ESI_RULE_MODE', 'research_prototype'),

    /**
     * handbook_pre_screening uses the 2012 edition ESI v4 handbook and a
     * separately reviewed provisional ESI 3 branch with Decision D deferred.
     * Inspect draft decisions: php artisan esi:preview --no-interaction
     * Patient narratives alone are never silently converted to clinical assessments.
     */

    /**
     * Study-specific mappings are separate from the clinical ESI v4 algorithm.
     * Keep original proposal definitions intact; approval provenance is separate.
     * Reported expert approval does not establish measured clinical performance.
     * See docs/rule-layer-guide.md before adding definitions or review records.
     */
    'provisional_rules' => DraftPatientMappings::definitions(),
    'provisional_approvals' => array_map(
        fn (string $hash): array => $reportedReview + ['definition_hash' => $hash],
        $reviewedDefinitionHashes,
    ),

    /**
     * Verbal review of the research_prototype catalog, as reported by the project team.
     * Accepted only while rule_version equals ResearchEsiRuleCatalog::version(); editing any
     * profile, danger signal, decision check, concept definition or engine file changes that
     * version, so readiness then blocks until a new review is recorded here.
     */
    'research_approval' => [
        'status' => 'approved',
        'reviewer' => 'Abigail Rose Gayatin-Sabandal, PHRN, USRN',
        'reviewed_at' => '2026-10-28',
        'record_id' => 'FIRSTLOOK-REPORTED-VERBAL-2026-10-28-01',
        'rule_version' => 'research-esi-patient-report-2.1.0+9143c6ea11951ef1',
        'approval_method' => 'verbal',
        'evidence_type' => 'user_report',
        'evidence_reference' => 'Project chat, 28 October 2026: the team reported that the reviewer verbally agreed to the version 2.1.0 changes. The written review form (FirstLook-Rule-Review-v2.1.0.docx) was not yet signed.',
        'scope_basis' => 'All 92 profiles of research-esi-patient-report-2.1.0 (11 at A, 50 at B, 31 at C), including the 22 profiles, 18 concepts, 4 profile changes and 2 engine changes added after the first TEST run; unchanged parts were reviewed for 2.0.0 on 27 October 2026.',
        'direct_reviewer_confirmation' => false,
        'signed_record_reference' => null,
    ],

    /**
     * Superseded record for version 2.0.0, kept for the first TEST run (firstlook-final-2026-10-27).
     * It no longer approves the active catalog because its rule_version does not match.
     */
    'research_approval_history' => [
        [
            'status' => 'approved',
            'reviewer' => 'Abigail Rose Gayatin-Sabandal, PHRN, USRN',
            'reviewed_at' => '2026-10-27',
            'record_id' => 'FIRSTLOOK-REPORTED-VERBAL-2026-10-27-01',
            'rule_version' => 'research-esi-patient-report-2.0.0+772a55351e054556',
            'approval_method' => 'verbal',
            'evidence_type' => 'user_report',
            'evidence_reference' => 'Project chat, 27 October 2026: the team reported that the reviewer validated the final rule set.',
            'scope_basis' => 'All 70 profiles of research-esi-patient-report-2.0.0 (9 at A, 47 at B, 14 at C), the A and B danger-signal lists, the decision checks and the normalized concept definitions.',
            'direct_reviewer_confirmation' => false,
            'signed_record_reference' => null,
        ],
    ],

    /**
     * Separate clinical-assessment mode remains unapproved. Entries must contain
     * status, reviewer, reviewed_at (YYYY-MM-DD), record_id and rule_version.
     * These are server-maintained review records, never patient or LLM input.
     */
    'approvals' => [],
];
