<?php

return [
    'mode' => 'handbook_pre_screening',

    /**
     * handbook_pre_screening uses the supplied May 2005 ESI v4 handbook and a
     * separately reviewed provisional ESI 3 branch with Decision D deferred.
     * Inspect draft decisions: php artisan esi:preview --no-interaction
     * Patient narratives alone are never silently converted to clinical assessments.
     */

    /**
     * Study-specific mappings are separate from the clinical ESI v4 algorithm.
     * The workbook has validated labels only, not documented general rules.
     * See docs/rule-layer-guide.md before adding definitions or review records.
     */
    'provisional_rules' => [],
    'provisional_approvals' => [],

    /**
     * No clinical approvals have been supplied. Each approved entry must contain
     * status, reviewer, reviewed_at (YYYY-MM-DD), record_id and rule_version.
     * These are server-maintained review records, never patient or LLM input.
     */
    'approvals' => [],
];
