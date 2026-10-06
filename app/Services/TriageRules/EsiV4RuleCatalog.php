<?php

namespace App\Services\TriageRules;

final class EsiV4RuleCatalog
{
    public const VERSION = 'esi-v4-adult-1.0.0';

    public const ALGORITHM = 'https://www.ahrq.gov/sites/default/files/publications2/files/esitriagealgorithm-v4_0.pdf';

    public const HANDBOOK = 'https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf';

    /** @param array<string, array<string, mixed>> $approvals */
    public function __construct(private readonly array $approvals = []) {}

    /** Approval changes produce a distinct saved revision, even when decision code is unchanged. */
    public function version(): string
    {
        $approvals = $this->approvals;
        ksort($approvals);
        foreach ($approvals as &$approval) {
            if (is_array($approval)) {
                ksort($approval);
            }
        }
        unset($approval);

        return self::VERSION.'+'.substr(hash('sha256', json_encode($approvals, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** @return array<string, array<string, mixed>> */
    public function rules(): array
    {
        $definitions = [
            'ESI4-A' => ['A', ['immediate_lifesaving_intervention_required'],
                'An assessed immediate life-saving intervention requirement assigns ESI 1. An explicit negative allows B.',
                'Chapter 2, Decision Point A'],
            'ESI4-B-HIGH-RISK' => ['B', ['high_risk_situation'],
                'An assessed high-risk situation supports ESI 2 after A is explicitly negative.',
                'Chapters 2 and 3, high-risk situations'],
            'ESI4-B-MENTAL-STATUS' => ['B', ['acute_confusion_lethargy_disorientation'],
                'An assessed acute change of this kind supports ESI 2. Chronic baseline confusion is not this criterion.',
                'Chapter 2, Decision Point B; Chapter 3, mental status'],
            'ESI4-B-PAIN-DISTRESS' => ['B', ['severe_pain_or_distress'],
                'Clinical assessment must establish severe pain or distress supporting ESI 2. A pain score alone is insufficient.',
                'Chapter 3, Pain and Distress'],
            'ESI4-C' => ['C', ['expected_esi_resources'],
                'An explicit clinical estimate of distinct ESI resources for this visit: zero gives ESI 5, one ESI 4, two or more proceeds to D.',
                'Chapter 4, Expected Resource Needs and Table 4-1'],
            'ESI4-D' => ['D', ['heart_rate', 'respiratory_rate', 'oxygen_saturation'],
                'Adults: measured HR >100/min, RR >20/min or oxygen saturation <92% requires consideration of ESI 2 by a clinician. All three available outside these danger zones support ESI 3.',
                'Chapter 5, The Role of Vital Signs in ESI Triage'],
        ];
        $rules = [];
        foreach ($definitions as $id => [$point, $fields, $condition, $section]) {
            $approval = $this->approvals[$id] ?? [];
            $approval = array_replace([
                'status' => 'pending_review', 'reviewer' => null, 'reviewed_at' => null,
                'record_id' => null, 'rule_version' => null,
            ], is_array($approval) ? $approval : []);
            $approved = $approval['status'] === 'approved'
                && $approval['rule_version'] === self::VERSION
                && $this->hasText($approval['reviewer']) && $this->hasText($approval['record_id'])
                && is_string($approval['reviewed_at']) && $this->validDate($approval['reviewed_at']);
            $rules[$id] = [
                'rule_id' => $id, 'decision_point' => $point, 'required_fields' => $fields,
                'condition' => $condition,
                'source' => ['algorithm' => self::ALGORITHM, 'handbook' => self::HANDBOOK, 'section' => $section],
                'reviewer_approval' => $approval, 'approved' => $approved, 'rule_version' => $this->version(),
            ];
        }

        return $rules;
    }

    private function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function validDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
