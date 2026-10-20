<?php

namespace App\Services\TriageRules;

use App\Services\Screening\NormalizedPatientFacts;

/**
 * Proposed patient-report adaptations of named ESI v4 examples.
 * These are review specimens, not a validated clinical decision instrument.
 * No dataset labels, TEST wording or LLM priorities were used to construct them.
 */
final class DraftPatientMappings
{
    public const VERSION = 'patient-mapping-draft-2-normalized';

    public const SOURCE_SHA256 = '0a3c86679e461b7410f8df534ee7cf59c7424dc4e522dbeb5fcb0f0cce62ed09';

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::proposals() as $id => $proposal) {
            $point = $proposal['decision_point'];
            $definitions[$id] = [
                'decision_point' => $point,
                'priority' => $proposal['candidate_priority'],
                'conditions' => $proposal['conditions'],
                'exclusions' => match ($point) {
                    'A' => [], 'B' => ['A' => self::exclusionsA()],
                    'C' => ['A' => self::exclusionsA(), 'B' => self::exclusionsB()],
                },
                'handbook_section' => $proposal['source_section'],
                'adaptation_rationale' => 'UNVALIDATED PROPOSAL. '.$proposal['adaptation']
                    .' Patient reports do not establish a clinical assessment. '.($point === 'A' ? ''
                        : 'The explicit negative-report bundle is a proposed proxy for earlier-decision exclusions, not a handbook-validated exclusion instrument. ')
                    .$proposal['unresolved_assessment'],
                'resource_rationale' => $proposal['resource_rationale'],
                'explanation' => $proposal['explanation'],
            ];
        }

        return $definitions;
    }

    /** Source describes a clinical example; the adaptation and omitted assessments are recorded separately. */
    public static function proposals(): array
    {
        $proposals = [
            'DRAFT-A-APNEA' => self::proposal('A', 1, 'Current cessation of breathing reported by a proxy', 'Chapter 2, pp. 8-11; Table 2-1 and responsiveness assessment',
                'Uses an explicit current proxy report of absent breathing and responsiveness as a candidate A signal.',
                'The report has not been confirmed by an airway/breathing assessment; historical or uncertain events do not match.', null,
                'The reported current cessation of breathing and responsiveness matches the draft immediate-intervention profile.'),

            'DRAFT-B-CHEST' => self::proposal('B', 2, 'Chest discomfort with associated concerning symptoms', 'Chapter 3, pp. 18-19, cardiovascular presentations',
                'Proposes a limited reported symptom cluster corresponding to the handbook discussion of cardiac risk.',
                'This does not diagnose ACS; other chest presentations and physiological instability require assessment.', null,
                'Reported chest discomfort and associated symptoms match a draft high-risk profile.'),

            'DRAFT-B-NEUROLOGICAL' => self::proposal('B', 2, 'Sudden new weakness with speech change', 'Chapter 3, p. 20, neurological presentations',
                'Maps an explicit report of sudden new motor and speech changes to the corresponding high-risk example.',
                'The neurological findings and A exclusion have not been clinically examined; no stroke diagnosis is inferred.', null,
                'The report of sudden weakness and speech change matches the draft high-risk profile.'),

            'DRAFT-B-HEADACHE' => self::proposal('B', 2, 'First sudden worst headache', 'Chapter 3, p. 20, neurological presentations',
                'Uses the explicitly sudden, unprecedented headache pattern discussed in the handbook.',
                'A pain number alone cannot satisfy this profile; the cause and clinical stability remain unassessed.', null,
                'The reported sudden first worst headache matches a draft high-risk profile.'),

            'DRAFT-B-ALLERGY' => self::proposal('B', 2, 'Throat tightening after a known allergen exposure', 'Chapter 3, p. 18, high-risk example before obtaining vital signs',
                'Adapts the handbook example of a reported known allergy with throat tightening after exposure.',
                'An immediate airway intervention would take precedence at A. Self-report cannot establish airway safety.', null,
                'Known allergy and throat tightening after exposure match a draft high-risk profile.'),

            'DRAFT-B-CHEMOTHERAPY' => self::proposal('B', 2, 'Reported fever during chemotherapy', 'Chapter 3, p. 20, general medical high-risk situations',
                'Proposes using reported fever together with current chemotherapy as a high-risk history combination.',
                'Subjective fever is not a measured temperature; neutropenia, infection and sepsis are not inferred.', null,
                'Reported fever during current chemotherapy matches a draft high-risk profile.'),

            'DRAFT-B-NEEDLESTICK' => self::proposal('B', 2, 'Recent used-needle exposure', 'Chapter 2, p. 12, high-risk examples',
                'Adapts the time-sensitive occupational needlestick example using an explicit exposure history.',
                'Exposure details and the need for intervention require clinician review; no infection is presumed.', null,
                'The reported recent used-needle exposure matches a draft time-sensitive risk profile.'),

            'DRAFT-B-OVERDOSE' => self::proposal('B', 2, 'Current reported excessive medicine ingestion', 'Chapter 3, p. 22, toxicological presentations',
                'Uses an explicit current ingestion report corresponding to the handbook overdose discussion.',
                'The drug, dose, physiological effects and lifesaving intervention need are unresolved; A overrides this candidate.', null,
                'The reported excessive medicine ingestion matches a draft high-risk profile.'),

            'DRAFT-B-NEW-CONFUSION' => self::proposal('B', 2, 'Proxy reports a new change from mental baseline', 'Chapter 3, p. 26, new-onset confusion, lethargy or disorientation',
                'Uses a proxy report that explicitly establishes new onset and change from baseline.',
                'Ordinary tiredness, unchanged chronic dementia, and unknown baseline do not satisfy this proposal; A still comes first.', null,
                'A reported new change in mental status matches the draft Decision B profile.'),

            'DRAFT-B-SELF-HARM' => self::proposal('B', 2, 'Current stated suicidal intent', 'Chapter 3, p. 20, mental-health high-risk situations',
                'Uses an explicit present-tense report of suicidal intent, not a historical mention or a keyword.',
                'This is a fictional review specimen; clinical risk assessment and any immediate physical intervention needs remain unresolved.', null,
                'The stated current intent matches a draft high-risk profile.'),

            'DRAFT-B-ONCOLOGY-PAIN' => self::proposal('B', 2, 'Severe pain with reported cancer history', 'Chapter 3, pp. 26-27, pain assessment and oncology example',
                'Proposes a contextual pain profile; a numeric score alone does not activate it.',
                'The handbook requires judgment about pain and interventions beyond triage. Patient statements cannot themselves supply that clinical judgment.', null,
                'The contextual severe-pain report matches a draft profile requiring clinical assessment.'),

            'DRAFT-C-RIGHT-LOWER-ABDOMEN' => self::proposal('C', 3, 'Right lower abdominal pain with nausea and reduced appetite', 'Chapter 4, Table 4-2, p. 33; Chapter 5, pp. 35-37',
                'Adapts the lower-right abdominal symptom example as a proposed provisional resource-profile prediction.',
                'Clinical examination, individual risk, pregnancy status where relevant and vital signs are unassessed. The negative report bundle cannot establish that every high-risk cause is excluded.',
                'The named handbook example anticipates multiple resource types, including laboratory studies and imaging. This is a proposed profile-level mapping, not a resource fact inferred or extracted from this patient. D remains unassessed.',
                'The report matches a draft multiple-resource profile; any candidate ESI 3 remains provisional.'),

            'DRAFT-C-LEG-AFTER-TRAVEL' => self::proposal('C', 3, 'One-sided lower-leg pain and swelling after prolonged travel', 'Chapter 4, Table 4-2, p. 33; Chapter 5, pp. 35-37',
                'Adapts the travel-associated leg symptom example without inventing a travel-duration threshold.',
                'Symptoms do not diagnose thrombosis. Chest symptoms, breathing difficulty or another high-risk feature must not be suppressed to fit this candidate. Clinical examination and D are missing.',
                'The handbook example anticipates laboratory studies and vascular imaging. Whether that expectation applies to this reported profile requires review; no tests-requested field supplies the resource estimate.',
                'The report matches a draft multiple-resource profile; any candidate ESI 3 remains provisional.'),

            'DRAFT-C-ANKLE' => self::proposal('C', 4, 'Isolated ankle twist with reported swelling and pain on weight bearing', 'Chapter 4, Table 4-2, p. 33',
                'Proposes an isolated ankle-injury profile based on patient-reported mechanism and symptoms.',
                'The source includes a physical finding; the draft has only reported swelling. Neurovascular injury, deformity and other injuries cannot be excluded by this text alone.',
                'The handbook example anticipates one imaging resource; crutches and an elastic wrap are not counted. Applying that expectation to self-report is an unvalidated adaptation, not an order or completed-test count.',
                'The report matches a draft one-resource ankle-injury profile.'),

            'DRAFT-C-URINARY' => self::proposal('C', 4, 'Reported isolated urinary burning and frequency', 'Chapter 4, Table 4-2, p. 33; Chapter 9, case 13',
                'Proposes a narrow reported urinary-symptom profile, with explicit contextual negatives.',
                'The source patient appears well and is afebrile; a denial of fever does not establish measured normal temperature or a normal examination. No UTI diagnosis is made.',
                'Urine testing and urine culture belong to one ESI laboratory resource group in the source example. This does not imply that the patient-reported profile always needs exactly one resource.',
                'The report matches a draft one-resource urinary-symptom profile.'),

            'DRAFT-C-FINGER-CUT' => self::proposal('C', 4, 'Isolated finger cut with reported controlled bleeding', 'Chapter 4, Table 4-1, pp. 30-31; Chapter 9, case 21',
                'Adapts the uncomplicated laceration example to a proposed patient-report profile.',
                'Wound depth, tendon injury, sensation, circulation and the need for closure require examination. The draft does not establish that a wound is uncomplicated.',
                'The source example anticipates a single repair procedure; tetanus immunization is not a second resource. Patient wording does not itself establish a need for suturing.',
                'The report matches a draft single-procedure profile for review.'),

            'DRAFT-C-REFILL' => self::proposal('C', 5, 'Routine blood-pressure prescription refill without acute complaints', 'Chapter 4, Tables 4-1 and 4-2, pp. 30 and 33',
                'Adapts the routine-refill example without generalizing to every medication or asymptomatic chronic condition.',
                'The source includes blood pressure and assessment. Symptoms alone cannot exclude a blood-pressure emergency or medication-specific risk; no refill or medication advice is provided.',
                'The source expects examination and a prescription, both outside counted ESI resources. That expectation requires review before applying it without the source measurements.',
                'The report matches a draft routine-refill profile with no counted resources in the source example.'),

            'DRAFT-C-WOUND-RECHECK' => self::proposal('C', 5, 'Requested simple dressing recheck with explicit absence of new symptoms', 'Chapter 4, Table 4-1, p. 30',
                'Uses a patient-described simple recheck as a proposed zero-resource profile.',
                'The handbook excludes simple wound care from resource counting; it does not say a recheck request proves that only simple care is needed. Wound assessment remains missing.',
                'A simple dressing/recheck is not a counted resource. The patient request is not proof of the expected visit requirements; this bridge needs explicit clinical review.',
                'The report matches a draft simple-recheck profile for review.'),
        ];
        foreach ($proposals as $id => &$proposal) {
            $proposal['conditions'] = self::normalizedConditions($id);
        }
        unset($proposal);

        return $proposals;
    }

    /** Explicit reported meanings; the English examples below are never used as matching values. */
    public static function exclusionsA(): array
    {
        return [self::concept('breathing_without_help'), self::concept('responds_to_voice'),
            self::concept('uncontrolled_bleeding', 'absent')];
    }

    public static function exclusionsB(): array
    {
        return [self::concept('responding_normally'), ...array_map(
            fn (string $code): array => self::concept($code, 'absent'),
            ['chest_discomfort', 'breathing_difficulty', 'fainting', 'new_confusion', 'severe_distress',
                'self_harm_thoughts', 'current_suicidal_intent', 'additional_symptoms', 'one_sided_weakness',
                'slurred_speech', 'headache', 'throat_tightness', 'fever', 'used_needle_puncture',
                'excess_medication_ingestion', 'severe_pain'],
        )];
    }

    /** Source example adaptations remain pending review; these are not exhaustive ESI criteria. */
    private static function normalizedConditions(string $id): array
    {
        $positive = match ($id) {
            'DRAFT-A-APNEA' => [],
            'DRAFT-B-CHEST' => ['chest_pressure', 'pain_radiates_left_arm', 'nausea', 'sweating', 'onset_today'],
            'DRAFT-B-NEUROLOGICAL' => ['one_sided_weakness', 'slurred_speech', 'sudden_onset', 'onset_today'],
            'DRAFT-B-HEADACHE' => ['headache', 'worst_ever_headache', 'sudden_onset'],
            'DRAFT-B-ALLERGY' => ['throat_tightness', 'peanut_exposure', 'peanut_allergy'],
            'DRAFT-B-CHEMOTHERAPY' => ['fever', 'current_chemotherapy'],
            'DRAFT-B-NEEDLESTICK' => ['used_needle_puncture', 'onset_just_now'],
            'DRAFT-B-OVERDOSE' => ['excess_medication_ingestion', 'onset_today'],
            'DRAFT-B-NEW-CONFUSION' => ['new_confusion', 'change_from_mental_baseline', 'onset_today'],
            'DRAFT-B-SELF-HARM' => ['current_suicidal_intent'],
            'DRAFT-B-ONCOLOGY-PAIN' => ['severe_pain', 'cancer_related_pain', 'cancer_history', 'unable_to_get_comfortable'],
            'DRAFT-C-RIGHT-LOWER-ABDOMEN' => ['right_lower_abdominal_pain', 'nausea', 'loss_of_appetite', 'onset_this_morning', 'moderate_pain'],
            'DRAFT-C-LEG-AFTER-TRAVEL' => ['one_sided_lower_leg_pain', 'one_sided_lower_leg_swelling', 'prolonged_car_travel', 'moderate_pain'],
            'DRAFT-C-ANKLE' => ['ankle_twist', 'ankle_swelling', 'pain_on_weight_bearing', 'mild_pain_or_discomfort'],
            'DRAFT-C-URINARY' => ['burning_urination', 'female_reported_sex', 'urinary_frequency', 'mild_pain_or_discomfort'],
            'DRAFT-C-FINGER-CUT' => ['finger_cut', 'separated_wound_edges', 'mild_pain_or_discomfort'],
            'DRAFT-C-REFILL' => ['routine_prescription_refill', 'ran_out_regular_bp_medicine', 'hypertension_history', 'regular_bp_medicine'],
            'DRAFT-C-WOUND-RECHECK' => ['dressing_recheck'],
        };
        $negative = match ($id) {
            'DRAFT-A-APNEA' => ['breathing', 'responds_to_voice'],
            'DRAFT-B-HEADACHE' => ['previous_headaches'],
            'DRAFT-C-RIGHT-LOWER-ABDOMEN', 'DRAFT-C-LEG-AFTER-TRAVEL', 'DRAFT-C-ANKLE' => ['known_medical_conditions'],
            'DRAFT-C-URINARY' => ['known_medical_conditions', 'pregnancy', 'fever', 'abdominal_pain', 'flank_pain', 'vomiting', 'vaginal_discharge'],
            'DRAFT-C-FINGER-CUT' => ['known_medical_conditions', 'ongoing_wound_bleeding'],
            'DRAFT-C-REFILL' => ['pain_or_discomfort'],
            'DRAFT-C-WOUND-RECHECK' => ['known_medical_conditions', 'pain_or_discomfort', 'new_wound_pain',
                'wound_redness', 'wound_swelling', 'wound_discharge', 'fever', 'worsening'],
            default => [],
        };

        return [...array_map(fn (string $code): array => self::concept($code), $positive),
            ...array_map(fn (string $code): array => self::concept($code, 'absent'), $negative)];
    }

    private static function concept(string $code, string $state = 'reported'): array
    {
        return ['concept' => $code, 'state' => $state];
    }

    private static function proposal(string $point, int $priority, string $title, string $section,
        string $adaptation, string $unresolved, ?string $resources, string $explanation): array
    {
        return ['title' => $title, 'decision_point' => $point, 'candidate_priority' => $priority,
            'source_section' => $section, 'adaptation' => $adaptation, 'unresolved_assessment' => $unresolved,
            'resource_rationale' => $resources, 'explanation' => $explanation,
            'validation_status' => 'unvalidated_draft', 'reviewer_approval_status' => 'pending_review',
            'source_document' => EsiV4RuleCatalog::SOURCE_DOCUMENT + ['sha256' => self::SOURCE_SHA256],
            'representation' => 'normalized_patient_concepts', 'normalization_version' => NormalizedPatientFacts::VERSION];
    }
}
