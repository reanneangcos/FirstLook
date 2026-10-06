<?php

return [
    /**
     * No clinical approvals have been supplied. Each approved entry must contain
     * status, reviewer, reviewed_at (YYYY-MM-DD), record_id and rule_version.
     * These are server-maintained review records, never patient or LLM input.
     */
    'approvals' => [],
];
