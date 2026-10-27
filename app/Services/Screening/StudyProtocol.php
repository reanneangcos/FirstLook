<?php

namespace App\Services\Screening;

use App\Services\TriageRules\DraftPatientMappings;
use App\Services\TriageRules\EsiV4RuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use App\Services\TriageRules\ProvisionalEsiRuleLayer;
use App\Services\TriageRules\ResearchEsiRuleCatalog;
use App\Services\TriageRules\ResearchEsiRuleLayer;
use App\Services\TriageRules\RuleLayer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class StudyProtocol
{
    public const VERSION = 'paired-study-v4-research-mode';

    public const PROMPT = 'screening-study-v3';

    public function isResearch(): bool
    {
        return config('esi.mode') === 'research_prototype';
    }

    public function promptVersion(): string
    {
        return $this->isResearch() ? 'screening-study-v8-research' : self::PROMPT;
    }

    public function catalog(): ProvisionalEsiRuleCatalog|ResearchEsiRuleCatalog
    {
        if ($this->isResearch()) {
            return new ResearchEsiRuleCatalog;
        }

        return new ProvisionalEsiRuleCatalog(config('esi.provisional_rules', []), config('esi.provisional_approvals', []));
    }

    /** Mechanical readiness does not certify the medical adequacy of the review. */
    public function readiness(): array
    {
        $blockers = [];
        if (! ($this->isResearch() && app(RuleLayer::class) instanceof ResearchEsiRuleLayer)
            && (config('esi.mode') !== 'provisional_patient_report' || ! app(RuleLayer::class) instanceof ProvisionalEsiRuleLayer)) {
            $blockers[] = 'The active engine requires clinical assessments that the patient-only workflow does not supply. Select provisional_patient_report after its mappings are reviewed.';
        }
        try {
            $rules = $this->catalog()->rules();
        } catch (\InvalidArgumentException) {
            $rules = [];
            $blockers[] = 'Patient-response definitions are invalid.';
        }
        if ($rules === []) {
            $blockers[] = 'Patient-response mappings are empty; handbook decision order alone cannot interpret these narrative fields.';
        }
        $unapproved = array_keys(array_filter($rules, fn (array $rule): bool => ! $rule['approved']));
        if ($unapproved !== [] && ! $this->isResearch()) {
            $blockers[] = 'Unapproved or changed definitions: '.implode(', ', $unapproved).'.';
        }
        if ($this->isResearch() && (new ResearchEsiRuleCatalog)->approval() === null) {
            $blockers[] = 'No nurse review record matches research rule version '.(new ResearchEsiRuleCatalog)->version().'. Record a new review in config/esi.php research_approval after any rule change.';
        }
        $missingLevels = array_values(array_diff([1, 2, 3, 4, 5], array_column(array_filter($rules, fn (array $rule): bool => $this->isResearch() || $rule['approved']), 'priority')));
        if ($missingLevels !== []) {
            $blockers[] = 'No approved patient mapping for ESI '.implode(', ', $missingLevels).'.';
        }
        if (! is_file(resource_path('prompts/'.$this->promptVersion().'.txt'))) {
            $blockers[] = 'The shared-criteria study prompt is missing.';
        }
        if (! config('triage.model') || ! config('triage.api_key')) {
            $blockers[] = 'Server API configuration is incomplete.';
        }

        return ['protocol_version' => self::VERSION, 'ready' => $blockers === [], 'blockers' => $blockers,
            'active_mode' => config('esi.mode'), 'rule_count' => count($rules),
            'approved_rule_count' => count($rules) - count($unapproved), 'missing_levels' => $missingLevels,
            'research_only' => $this->isResearch(), 'clinical_validation_established' => false,
            'methodology_change' => $this->isResearch() ? 'Research-authored profiles replace mandatory negative bundles with positive-danger screening; unreported risks stay unassessed. The catalog review record covers the rule specification, not measured clinical accuracy.' : null,
            'research_approval' => $this->isResearch() ? (new ResearchEsiRuleCatalog)->approval() : null,
            'source_document' => EsiV4RuleCatalog::SOURCE_DOCUMENT,
            'limitation' => 'Checks configuration and review records, not clinical validity or extraction accuracy. Rules consume normalized patient concepts with original evidence.'];
    }

    public function assertReady(): void
    {
        $report = $this->readiness();
        if (! $report['ready']) {
            throw new RuntimeException(implode("\n", $report['blockers']));
        }
    }

    /** Only clinical criteria are sent to A; reviewer identities and reference labels stay local. */
    public function criteria(): array
    {
        return array_values(array_map(fn (array $rule): array => array_intersect_key($rule, array_flip([
            'decision_point', 'priority', 'conditions', 'exclusions', 'handbook_section',
            'adaptation_rationale', 'resource_rationale', 'explanation', 'rule_id', 'all', 'any', 'none', 'min_age', 'expected_resource_types',
        ])), $this->catalog()->rules()));
    }

    public function prompt(): string
    {
        return str_replace(['{{reviewed_criteria}}', '{{normalization_instructions}}', '{{danger_signals}}', '{{decision_checks}}'],
            [json_encode($this->criteria(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), (new NormalizedPatientFacts)->instructions(),
                json_encode(['A' => ResearchEsiRuleCatalog::A_SIGNALS, 'B' => ResearchEsiRuleCatalog::B_SIGNALS], JSON_THROW_ON_ERROR),
                json_encode(ResearchEsiRuleCatalog::decisionChecks(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            file_get_contents(resource_path('prompts/'.$this->promptVersion().'.txt')));
    }

    /** Secrets and machine paths are deliberately excluded. Local code edits invalidate a freeze. */
    public function snapshot(): array
    {
        $files = [];
        foreach ([app_path(), resource_path('prompts'), config_path()] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                $relative = substr($file->getPathname(), strlen(base_path()) + 1);
                $files[$relative] = hash_file('sha256', $file->getPathname());
            }
        }
        $files['composer.lock'] = hash_file('sha256', base_path('composer.lock'));
        ksort($files);
        $settings = array_intersect_key(config('triage'), array_flip(['model', 'reasoning_effort', 'max_output_tokens',
            'timeout_seconds', 'connect_timeout_seconds', 'max_attempts', 'retry_delay_ms', 'schema_version']));
        $settings['max_attempts'] = min(3, max(1, $settings['max_attempts']));

        return ['protocol_version' => self::VERSION, 'prompt_version' => $this->promptVersion(),
            'prompt_sha256' => hash('sha256', str_replace('{{response_language}}', 'English', $this->prompt())),
            'reviewed_rules' => $this->catalog()->rules(), 'rule_version' => app(RuleLayer::class)->version(),
            'research_only' => $this->isResearch(), 'criteria_status' => $this->criteriaStatus(),
            'settings' => $settings, 'validation_version' => ScreeningOutput::VALIDATION_VERSION,
            'retry_feedback_version' => ScreeningService::RETRY_FEEDBACK_VERSION,
            'schema' => app(ScreeningOutput::class)->schema(), 'files' => $files];
    }

    public function criteriaStatus(): string
    {
        if (! $this->isResearch()) {
            return 'reviewed_definitions';
        }

        return (new ResearchEsiRuleCatalog)->approval() === null ? 'unvalidated_research_adaptation' : 'nurse_reviewed_research_adaptation';
    }

    public static function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function frozen(string $id, bool $checkCurrentConfiguration = true): array
    {
        StudyManifest::validateId($id);
        $raw = Storage::disk('local')->get('studies/'.$id.'/freeze.json');
        if ($raw === null) {
            throw new RuntimeException('No freeze exists for this study ID.');
        }
        $envelope = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $payload = $envelope['payload'] ?? null;
        if (! is_array($payload) || ($envelope['sha256'] ?? null) !== self::hash($payload)
            || ($payload['study_id'] ?? null) !== $id) {
            throw new RuntimeException('The frozen record failed its integrity check.');
        }
        app(StudyManifest::class)->validate($payload['manifest'] ?? null);
        if (($payload['manifest_sha256'] ?? null) !== self::hash($payload['manifest'])) {
            throw new RuntimeException('Frozen input hash does not match.');
        }
        if ($checkCurrentConfiguration) {
            $this->assertReady();
            if (self::hash($payload['snapshot']) !== self::hash($this->snapshot())) {
                throw new RuntimeException('Code, prompt, rules or settings changed after the freeze. This study cannot resume with mixed configurations.');
            }
        }

        return $envelope;
    }

    /** Concrete handbook predicates and patient-report adaptations for the nurse's review. */
    public function reviewPacket(): array
    {
        $questions = [
            'ESI4-A' => ['fields' => ['main_complaint', 'symptom_description', 'other_symptoms'],
                'decision_needed' => 'Specify which explicit patient/proxy reports can support a provisional immediate-intervention prediction and which reports support its exclusion. Typing normally or no matching keyword cannot exclude A.'],
            'ESI4-B-HIGH-RISK' => ['fields' => ['main_complaint', 'symptom_description', 'known_conditions', 'onset', 'worsening'],
                'decision_needed' => 'Specify complete context for each supported high-risk presentation and explicit earlier-A exclusions. Do not turn the handbook examples into unconditional keywords.'],
            'ESI4-B-MENTAL-STATUS' => ['fields' => ['symptom_description', 'other_symptoms', 'onset', 'duration'],
                'decision_needed' => 'Specify accepted reports of a new change from baseline and distinguish chronic confusion, ordinary tiredness and unresponsiveness.'],
            'ESI4-B-PAIN-DISTRESS' => ['fields' => ['reported_severity', 'symptom_description', 'other_symptoms'],
                'decision_needed' => 'Specify the contextual conditions that support severe distress; a numerical pain score by itself cannot supply clinical judgment.'],
            'ESI4-C' => ['fields' => ['main_complaint', 'symptom_description', 'other_symptoms', 'known_conditions'],
                'decision_needed' => 'Specify general patient-report profiles supporting zero, one or at least two expected resources, with A/B exclusions and the resource rationale. Tests completed/requested and devices are not automatic resource counts.'],
            'ESI4-D-PROVISIONAL' => ['fields' => [],
                'decision_needed' => 'Review numerical provisional ESI 3 only after supported A/B exclusions and the C two-or-more mapping. D remains unassessed; unclear A/B/C must remain Needs Review.'],
        ];

        return ['readiness' => $this->readiness(), 'handbook_decisions' => (new EsiV4RuleCatalog(config('esi.approvals'), true))->rules(),
            'patient_mapping_review' => $questions, 'configured_patient_rules' => $this->catalog()->rules(),
            'draft_proposals' => DraftPatientMappings::proposals(),
            'coverage_limits' => ['Selected adult handbook examples, not all possible conditions.',
                'Luna normalizes languages and clear typos into the same concept vocabulary; extraction accuracy needs evaluation.',
                'Rules compare concept identifiers and states; original-language evidence is retained for audit.',
                'A/B negative-report bundles and C resource profiles are study adaptations; review provenance is stored per rule and does not establish measured clinical accuracy.',
                'Unknown, conflicting or unmatched required information remains Needs Review.'],
            'concept_definitions' => NormalizedPatientFacts::definitions(),
            'instruction' => 'Review clinical meanings, conditions and exclusions, not phrase lists or TEST labels. Record rationale, reviewer, date, record ID and exact definition hash. Evaluate language extraction separately. No approval is created by this export.'];
    }
}
