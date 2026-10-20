<?php

namespace App\Services\TriageRules;

final class EsiV4RuleCatalog
{
    public const VERSION = 'esi-v4-adult-1.3.0';

    public const PROVISIONAL_THREE_LABEL = 'Most likely ESI 3 - nurse evaluation and vital signs required';

    public const ALGORITHM = 'https://www.ahrq.gov/sites/default/files/publications2/files/esitriagealgorithm-v4_0.pdf';

    public const HANDBOOK = 'https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf';

    public const SOURCE_DOCUMENT = [
        'filename' => 'GOVPUB-HE20_6500-PURL-gpo23161.pdf',
        'title' => 'Emergency Severity Index, Version 4: Implementation Handbook',
        'edition' => '2012 Edition',
        'publication_number' => 'AHRQ 12-0014',
        'published' => 'November 2011',
        'url' => self::HANDBOOK,
    ];

    /** Table 2-1, printed p. 9. Each token means an assessed immediate requirement. */
    public const LIFESAVING_INTERVENTIONS = [
        'bag_mask_ventilation', 'intubation', 'surgical_airway', 'emergent_cpap', 'emergent_bipap',
        'defibrillation', 'emergent_cardioversion', 'external_pacing', 'chest_needle_decompression',
        'pericardiocentesis', 'open_thoracotomy', 'intraosseous_access',
        'significant_iv_fluid_resuscitation', 'blood_administration', 'control_of_major_bleeding',
        'naloxone', 'dextrose_50_percent', 'dopamine', 'atropine', 'adenosine',
    ];

    /** Table 2-1: these alone neither establish nor exclude an ESI 1 condition. */
    public const NON_LIFESAVING_INTERVENTIONS = [
        'oxygen_administration', 'cardiac_monitor', 'ecg', 'laboratory_tests',
        'diagnostic_ultrasound', 'iv_access', 'saline_lock', 'aspirin', 'iv_nitroglycerin',
        'antibiotics', 'heparin', 'pain_medication', 'beta_agonist_treatment',
    ];

    /** @param array<string, array<string, mixed>> $approvals */
    public function __construct(private readonly array $approvals = [], private readonly bool $deferVitalSigns = false) {}

    public function defersVitalSigns(): bool
    {
        return $this->deferVitalSigns;
    }

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

        return self::VERSION.($this->deferVitalSigns ? '-provisional-d' : '').'+'.substr(hash('sha256', json_encode($approvals, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** @return array<string, array<string, mixed>> */
    public function rules(): array
    {
        $definitions = [
            'ESI4-A' => ['A', ['immediate_lifesaving_intervention_required'],
                'An assessed immediate life-saving intervention requirement assigns ESI 1. An explicit negative allows B.',
                'Chapter 2, pp. 8-11; Table 2-1, p. 9'],
            'ESI4-B-HIGH-RISK' => ['B', ['high_risk_situation'],
                'An assessed high-risk situation supports ESI 2 after A is explicitly negative.',
                'Chapter 2, pp. 11-12; Chapter 3, pp. 17-25'],
            'ESI4-B-MENTAL-STATUS' => ['B', ['acute_confusion_lethargy_disorientation'],
                'An assessed acute change of this kind supports ESI 2. Chronic baseline confusion is not this criterion.',
                'Chapter 2, p. 12; Chapter 3, pp. 25-26'],
            'ESI4-B-PAIN-DISTRESS' => ['B', ['severe_pain_or_distress'],
                'Clinical assessment must establish severe pain or distress supporting ESI 2. A pain score alone is insufficient.',
                'Chapter 2, pp. 12-13; Chapter 3, pp. 26-27'],
            'ESI4-C' => ['C', ['expected_esi_resources'],
                'An explicit clinical estimate of distinct ESI resources for this visit: zero gives ESI 5, one ESI 4, two or more proceeds to D.',
                'Chapter 4, pp. 29-33; Table 4-1, p. 30; counting examples, p. 31'],
            'ESI4-D' => ['D', ['heart_rate', 'respiratory_rate', 'oxygen_saturation'],
                'Adults: measured HR >100/min, RR >20/min or oxygen saturation <92% requires consideration of ESI 2 by a clinician. All three available outside these danger zones support ESI 3.',
                'Chapter 5, pp. 35-38; Figure 5-1'],
        ];
        if ($this->deferVitalSigns) {
            unset($definitions['ESI4-D']);
            $definitions['ESI4-D-PROVISIONAL'] = ['D', ['immediate_lifesaving_intervention_required', 'high_risk_situation',
                'acute_confusion_lethargy_disorientation', 'severe_pain_or_distress', 'expected_esi_resources'],
                'Only after explicit negative A and all B criteria, and an established estimate of two or more resources: predict provisional ESI 3; Decision D remains unassessed. Unclear A, B or C never qualifies.',
                'Study adaptation of Chapters 4-5, pp. 29-38; deferral is not a completed handbook ESI 3 decision'];
        }
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
                'source' => ['algorithm' => self::ALGORITHM, 'handbook' => self::SOURCE_DOCUMENT['filename'],
                    'document' => self::SOURCE_DOCUMENT, 'section' => $section],
                'reviewer_approval' => $approval, 'approved' => $approved, 'rule_version' => $this->version(),
                'is_study_adaptation' => $id === 'ESI4-D-PROVISIONAL',
                'clinical_specification' => $this->specification($id),
                'alternative_inputs' => match ($id) {
                    'ESI4-A' => ['immediate_interventions_required' => [
                        'type' => 'list of service tokens', 'positive_tokens' => self::LIFESAVING_INTERVENTIONS,
                        'non_lifesaving_tokens' => self::NON_LIFESAVING_INTERVENTIONS,
                        'constraint' => 'Verified current immediate requirements only. A list with no lifesaving match cannot establish a negative A.',
                    ]],
                    'ESI4-C' => ['expected_resource_plan' => [
                        'type' => ['complete' => 'boolean', 'items' => 'list of service tokens'],
                        'services' => EsiV4ResourceCounter::SERVICES,
                        'constraint' => 'Verified expected current-visit services only. An incomplete plan must establish at least two resources.',
                    ]],
                    default => [],
                },
            ];
        }

        return $rules;
    }

    /** @return array{positive: list<string>, exclusions: list<string>, input_limit: string} */
    private function specification(string $id): array
    {
        return match ($id) {
            'ESI4-A' => [
                'positive' => ['An immediate airway, breathing, circulatory or emergency medication intervention is required.',
                    'Handbook examples include apnea, pulselessness, acute unresponsiveness, and respiratory compromise requiring immediate lifesaving support.'],
                'exclusions' => ['IV access, supplemental oxygen, monitoring, ECG or laboratory testing alone does not establish this criterion.',
                    'A patient typing a message does not establish that this criterion is negative.'],
                'input_limit' => 'The intervention requirement must be established. A reported device, diagnosis or historical event is not an immediate intervention assessment.',
            ],
            'ESI4-B-HIGH-RISK' => [
                'positive' => ['After A is negative, an established high-risk situation supports ESI 2.',
                    'Handbook examples: active chest pain suspicious for acute coronary syndrome; stroke signs; healthcare-worker needle stick; fever during chemotherapy; suicidal or homicidal presentation.'],
                'exclusions' => ['These examples are contextual clinical presentations, not unrestricted keyword triggers.',
                    'A negative keyword search does not establish absence of high risk.'],
                'input_limit' => 'Chest pain alone does not establish suspected acute coronary syndrome. Reported fever is not an inferred temperature. An unsupported clinical interpretation remains unknown.',
            ],
            'ESI4-B-MENTAL-STATUS' => [
                'positive' => ['After A is negative, newly developed confusion, lethargy or disorientation supports ESI 2.'],
                'exclusions' => ['Chronic baseline confusion alone does not establish the acute-change criterion.',
                    'Unresponsiveness requiring lifesaving intervention belongs at A.'],
                'input_limit' => 'Onset and change from baseline must be established. A vague report of tiredness does not establish lethargy.',
            ],
            'ESI4-B-PAIN-DISTRESS' => [
                'positive' => ['After A is negative, an assessment that severe pain or physiological/psychological distress warrants high-priority care supports ESI 2.'],
                'exclusions' => ['A pain rating of 7/10 or higher prompts consideration; it does not automatically assign ESI 2.'],
                'input_limit' => 'Preserve a reported pain score as a report. Do not infer examination findings or the required clinical judgment from that score alone.',
            ],
            'ESI4-C' => [
                'positive' => ['After explicit negative A and all B criteria: zero expected resource types gives ESI 5; one gives ESI 4; two or more proceeds to D.',
                    'Resources refer to the expected current visit, counted by distinct ESI types rather than the number of tests.'],
                'exclusions' => ['Past tests, requested tests and devices in place do not automatically establish expected resources.',
                    'History/examination, point-of-care tests, oral medication, prescription refills, tetanus immunization and simple dressings are not counted resources.'],
                'input_limit' => 'An explicit supported estimate is required. An incomplete list cannot establish zero or exactly one. Neither the LLM nor the engine invents a resource count.',
            ],
            'ESI4-D-PROVISIONAL' => [
                'positive' => ['A is explicitly negative; all three B criteria are explicitly negative; the supported resource estimate is at least two.',
                    'Return a numerical prediction of 3 with a provisional label and Decision D not assessed.'],
                'exclusions' => ['Missing or conflicting A/B/C facts require Needs Review with no level.',
                    'No default-to-3, no assumption of normal vital signs, and no claim that the patient is safe to wait.'],
                'input_limit' => 'This is a proposed study adaptation requiring separate approval. "Most likely" is a display phrase, not a calibrated probability.',
            ],
            'ESI4-D' => [
                'positive' => ['Adult HR >100/min, RR >20/min or oxygen saturation <92% prompts consideration of ESI 2 with clinical judgment.',
                    'With A/B excluded and at least two resources, valid measured vital signs outside the danger zone support ESI 3.'],
                'exclusions' => ['An abnormal vital does not automatically assign ESI 2.', 'Missing measurements do not imply normal values.'],
                'input_limit' => 'This clinical path requires measured vital signs and stays separate from the study adaptation.',
            ],
        };
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
