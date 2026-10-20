<?php

use App\Services\TriageRules\DraftPatientMappings;

return [
    'mode' => 'provisional_patient_report',

    /**
     * handbook_pre_screening uses the 2012 edition ESI v4 handbook and a
     * separately reviewed provisional ESI 3 branch with Decision D deferred.
     * Inspect draft decisions: php artisan esi:preview --no-interaction
     * Patient narratives alone are never silently converted to clinical assessments.
     */

    /**
     * Study-specific mappings are separate from the clinical ESI v4 algorithm.
     * Handbook-linked patient mappings are draft proposals, not validated rules.
     * Their patient-report exclusions and resource expectations require review.
     * See docs/rule-layer-guide.md before adding definitions or review records.
     */
    'provisional_rules' => DraftPatientMappings::definitions(),
    'provisional_approvals' => [],

    /**
     * No clinical approvals have been supplied. Each approved entry must contain
     * status, reviewer, reviewed_at (YYYY-MM-DD), record_id and rule_version.
     * These are server-maintained review records, never patient or LLM input.
     */
    'approvals' => [],
];
