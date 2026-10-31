<?php

namespace App\Services\TriageRules;

use App\Services\Screening\NormalizedPatientFacts;

final class ResearchEsiRuleCatalog
{
    public const VERSION = 'research-esi-patient-report-1.1.0';

    public const SOURCES = [
        'handbook' => 'https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf',
        'soufi_2018' => 'https://pubmed.ncbi.nlm.nih.gov/29673601/',
        'elias_2015' => 'https://medinform.jmir.org/2015/2/e23/',
        'gaber_2025' => 'https://www.nature.com/articles/s41746-025-01684-1',
        'sharma_2026' => 'https://pubmed.ncbi.nlm.nih.gov/42612553/',
    ];


    public const A_SIGNALS = ['unresponsive_now', 'uncontrolled_bleeding', 'active_convulsions', 'blue_colour', 'gasping_or_noisy_breathing'];

    public const B_SIGNALS = ['chest_discomfort', 'breathing_difficulty', 'fainting', 'new_confusion', 'severe_distress',
        'one_sided_weakness', 'new_motor_weakness', 'slurred_speech', 'throat_tightness', 'lip_or_tongue_swelling',
        'swallowing_difficulty', 'current_suicidal_intent', 'self_harm_thoughts', 'excess_medication_ingestion',
        'used_needle_puncture', 'worst_ever_headache', 'vision_change', 'vomiting_blood', 'bloody_stool', 'coughing_blood',
        'saddle_numbness', 'difficulty_urinating', 'recent_seizure', 'severe_pain'];

    public function version(): string
    {
        return self::VERSION.'+'.substr(hash('sha256', json_encode([
            $this->definitions(), self::A_SIGNALS, self::B_SIGNALS,
            NormalizedPatientFacts::definitions(),
            hash_file('sha256', __FILE__), hash_file('sha256', __DIR__.'/ResearchEsiRuleLayer.php'),
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /** All mappings are researcher-authored adaptations; no historical nurse approval is inherited. */
    public function rules(): array
    {
        $version = $this->version();

        return array_map(function (array $rule) use ($version): array {
            $concepts = array_keys($rule['all']);
            foreach ($rule['any'] as $group) {
                $concepts = [...$concepts, ...array_keys($group)];
            }

            return $rule + [
                'required_concepts' => array_values(array_unique($concepts)),
                'condition' => 'All required facts and at least one fact in each alternative group must match. Earlier reported danger signals take precedence.',
                'required_fields' => ['age', 'patient-report evidence in permitted source fields'],
                'source' => ['handbook' => self::SOURCES['handbook'], 'section' => $rule['handbook_section']],
                'reviewer_approval' => ['status' => 'not_clinically_validated', 'reviewer' => null, 'reviewed_at' => null],
                'approved' => false, 'rule_version' => $version,
                'adaptation_rationale' => 'Research-only patient-report prediction. Earlier A/B conditions are screened for positive reports, not certified absent. Resource profiles are fixed researcher hypotheses, not observed resource use.',
            ];
        }, $this->definitions());
    }

    /** AND requirements plus AND-of-OR groups; no wording, case IDs, labels, or LLM priorities. */
    private function definitions(): array
    {
        $r = 'reported';
        $a = 'absent';

        return [
            $this->rule('RP-A-APNEA', 'A', ['breathing' => $a], [], [], 'Chapter 2, pp. 8–11', 'Explicit current absence of breathing.'),
            $this->rule('RP-A-AIRWAY', 'A', ['unresponsive_now' => $r], [
                ['gasping_or_noisy_breathing' => $r, 'material_in_mouth' => $r, 'blue_colour' => $r],
            ], [], 'Chapter 2, pp. 9–11; airway and responsiveness', 'Unresponsiveness with reported airway or breathing danger.'),
            $this->rule('RP-A-BLEEDING', 'A', ['uncontrolled_bleeding' => $r], [
                ['pallor' => $r, 'fainting' => $r, 'unresponsive_now' => $r],
            ], [], 'Chapter 2, Table 2-1, p. 10', 'Uncontrolled bleeding with reported deterioration.'),
            $this->rule('RP-A-CONVULSIONS', 'A', ['active_convulsions' => $r], [
                ['unresponsive_now' => $r, 'blue_colour' => $r],
            ], [], 'Chapter 2, p. 11, actively seizing example', 'Ongoing convulsions with reported unresponsiveness or blue colour.'),

            $this->rule('RP-B-CHEST', 'B', ['chest_discomfort' => $r], [
                ['pain_radiates_left_arm' => $r, 'sweating' => $r, 'breathing_difficulty' => $r, 'nausea' => $r],
            ], [], 'Chapter 3, pp. 18–19', 'Chest discomfort with an associated concerning report.'),
            $this->rule('RP-B-TEARING-PAIN', 'B', ['sudden_onset' => $r, 'tearing_pain' => $r], [
                ['back_pain' => $r, 'abdominal_pain' => $r, 'chest_discomfort' => $r],
            ], [], 'Chapter 3, p. 19, cardiovascular and abdominal presentations', 'Abrupt tearing torso pain.'),
            $this->rule('RP-B-NEUROLOGICAL', 'B', ['sudden_onset' => $r], [
                ['one_sided_weakness' => $r, 'new_motor_weakness' => $r, 'slurred_speech' => $r],
            ], [], 'Chapter 3, p. 20, neurological presentations', 'Sudden motor or speech change.'),
            $this->rule('RP-B-BACK-NEUROLOGICAL', 'B', ['back_pain' => $r, 'bilateral_leg_weakness' => $r], [
                ['saddle_numbness' => $r, 'difficulty_urinating' => $r],
            ], [], 'Chapter 3, p. 20; adaptation of motor-deficit high risk', 'Back pain with bilateral weakness and a reported sensory or urinary change.'),
            $this->rule('RP-B-HEADACHE', 'B', ['sudden_onset' => $r, 'worst_ever_headache' => $r], [], [], 'Chapter 3, p. 20', 'Sudden worst-ever headache.'),
            $this->rule('RP-B-ALLERGY', 'B', ['allergen_exposure' => $r], [
                ['throat_tightness' => $r, 'lip_or_tongue_swelling' => $r],
                ['throat_tightness' => $r, 'breathing_difficulty' => $r, 'swallowing_difficulty' => $r],
            ], [], 'Chapter 3, pp. 18–19', 'Exposure with reported throat, breathing or swallowing symptoms.'),
            $this->rule('RP-B-EYE', 'B', ['eye_pain' => $r, 'vision_change' => $r, 'sudden_onset' => $r], [], [], 'Chapter 3, p. 21, ophthalmological presentations', 'New painful visual disturbance.'),
            $this->rule('RP-B-LEG-BREATHING', 'B', ['one_sided_lower_leg_swelling' => $r, 'breathing_difficulty' => $r], [], [], 'Chapter 3, pp. 19 and 22, respiratory high risk; symptom-cluster adaptation', 'One-sided leg swelling with reported breathlessness.'),
            $this->rule('RP-B-CHEMOTHERAPY', 'B', ['current_chemotherapy' => $r, 'fever' => $r], [], [], 'Chapter 3, p. 20', 'Reported fever during chemotherapy.'),
            $this->rule('RP-B-CONFUSION', 'B', ['new_confusion' => $r], [], [], 'Chapter 3, p. 26', 'Explicit new confusion.'),
            $this->rule('RP-B-SELF-HARM', 'B', ['current_suicidal_intent' => $r], [], [], 'Chapter 3, p. 20', 'Reported current suicidal intent.'),
            $this->rule('RP-B-OVERDOSE', 'B', ['excess_medication_ingestion' => $r], [], [], 'Chapter 3, p. 22', 'Reported current excessive medication ingestion.'),
            $this->rule('RP-B-NEEDLESTICK', 'B', ['used_needle_puncture' => $r, 'onset_just_now' => $r], [], [], 'Chapter 2, p. 12', 'Recent used-needle exposure.'),
            $this->rule('RP-B-SEIZURE', 'B', ['recent_seizure' => $r], [], [], 'Chapter 3, p. 20', 'Reported recent seizure.'),
            $this->rule('RP-B-CANCER-PAIN', 'B', ['severe_pain' => $r, 'cancer_related_pain' => $r, 'unable_to_get_comfortable' => $r], [], [], 'Chapter 3, pp. 26–27', 'Contextual severe cancer-related pain; no numerical pain cutoff.'),

            $this->rule('RP-C-ABDOMEN', 'C', ['abdominal_pain' => $r], [
                ['nausea' => $r, 'loss_of_appetite' => $r, 'vomiting' => $r],
            ], ['laboratory', 'imaging'], 'Chapter 4, Table 4-2, p. 33; broader abdominal adaptation', 'Abdominal symptom cluster.', 'Hypothesis: laboratory investigation plus abdominal imaging.'),
            $this->rule('RP-C-FEBRILE-COUGH', 'C', ['cough' => $r, 'fever' => $r, 'general_weakness' => $r], [], ['laboratory', 'imaging'], 'Chapter 4, pp. 29–33; Chapter 5 respiratory examples', 'Cough with subjective fever and weakness.', 'Hypothesis: laboratory investigation plus chest imaging. No normal vital signs assumed.'),
            $this->rule('RP-C-VOMITING', 'C', ['repeated_vomiting' => $r], [
                ['poor_oral_intake' => $r, 'dizziness' => $r],
            ], ['laboratory', 'iv_hydration'], 'Chapter 4, Table 4-1, p. 30; Chapter 9 vomiting examples', 'Repeated vomiting with intake difficulty or dizziness.', 'Hypothesis: laboratory investigation and IV hydration; neither is an order or an observed fact.'),
            $this->rule('RP-C-DIARRHEA', 'C', ['diarrhea' => $r, 'general_weakness' => $r, 'orthostatic_lightheadedness' => $r], [], ['laboratory', 'iv_hydration'], 'Chapter 4, pp. 29–31; patient-report adaptation', 'Diarrhea with weakness and lightheadedness on standing.', 'Hypothesis: laboratory investigation and IV hydration. No diagnosis of dehydration is inferred.'),
            $this->rule('RP-C-LEG-SWELLING', 'C', ['one_sided_lower_leg_pain' => $r, 'one_sided_lower_leg_swelling' => $r], [], ['laboratory', 'imaging'], 'Chapter 4, Table 4-2, p. 33; broader leg-profile adaptation', 'One-sided leg pain and swelling.', 'Hypothesis: laboratory investigation plus vascular imaging.'),
            $this->rule('RP-C-LIMB-INJURY', 'C', ['limb_injury' => $r, 'limb_pain' => $r], [
                ['limb_swelling' => $r, 'preserved_limb_function' => $r, 'pain_on_weight_bearing' => $r],
            ], ['imaging'], 'Chapter 4, pp. 31–32, ankle example; broader limb adaptation', 'Localized limb injury profile.', 'Hypothesis: one imaging resource; supports and examination do not add resources.'),
            $this->rule('RP-C-URINARY', 'C', ['burning_urination' => $r, 'urinary_frequency' => $r, 'fever' => $a], [], ['laboratory'], 'Chapter 4, Table 4-2, p. 33; broader urinary adaptation', 'Urinary burning and frequency with an explicit fever denial.', 'Hypothesis: urine laboratory testing as one resource group; pregnancy and clinical findings remain unassessed.'),
            $this->rule('RP-C-CUT', 'C', ['skin_cut' => $r, 'separated_wound_edges' => $r, 'bleeding_stopped' => $r], [], ['simple_procedure'], 'Chapter 4, pp. 30–32', 'Reported cut with separated edges after bleeding stopped.', 'Hypothesis: one repair procedure; need for repair is not a patient-established fact.'),
            $this->rule('RP-C-EAR-OBJECT', 'C', ['ear_foreign_body' => $r], [], ['simple_procedure'], 'Chapter 4, Table 4-1, p. 30; procedure adaptation', 'Reported object lodged in the ear.', 'Hypothesis: one simple removal procedure; actual examination and procedure complexity are unavailable.'),
            $this->rule('RP-C-REFILL', 'C', ['routine_prescription_refill' => $r], [
                ['feels_well' => $r, 'no_acute_symptoms' => $r],
            ], [], 'Chapter 2, Table 2-4, p. 14; Chapter 4 resource exclusions', 'Routine refill with an explicit report of feeling well or no acute symptoms.', 'Hypothesis: examination and prescription only, neither an ESI resource.'),
            $this->rule('RP-C-LOCAL-SKIN', 'C', ['localized_itchy_skin' => $r], [
                ['feels_well' => $r, 'additional_symptoms' => $a, 'breathing_difficulty' => $a],
            ], [], 'Chapter 2, Table 2-4, p. 14; skin-example adaptation', 'Limited itchy skin complaint with supporting context.', 'Hypothesis: examination and oral/topical prescription only; no counted resources.'),
            $this->rule('RP-C-IMPROVING-URI', 'C', ['cough' => $r, 'symptoms_improving' => $r, 'usual_activities_preserved' => $r, 'fever' => $a], [
                ['runny_nose' => $r, 'scratchy_throat' => $r],
                ['breathing_without_help' => $r, 'breathing_difficulty' => $a],
            ], [], 'Chapter 4, pp. 29–31; researcher zero-resource hypothesis', 'Improving upper-respiratory reports with preserved daily function and explicit fever denial.', 'Hypothesis: history/examination and advice only. Persistent or worsening cough does not match this profile.'),
            $this->rule('RP-C-NASAL', 'C', ['runny_nose' => $r, 'sneezing' => $r, 'fever' => $a, 'breathing_difficulty' => $a, 'eating_drinking_normally' => $r], [], [], 'Chapter 4, pp. 29–31; researcher zero-resource hypothesis', 'Nasal symptoms with explicit fever/breathing denials and preserved intake.', 'Hypothesis: examination and oral prescription only; no counted resources.'),
        ];
    }

    /** @param array<string, string> $all @param list<array<string, string>> $any @param list<string> $resources */
    private function rule(string $id, string $point, array $all, array $any, array $resources, string $section, string $explanation, ?string $rationale = null): array
    {
        return ['rule_id' => $id, 'decision_point' => $point,
            'priority' => $point === 'A' ? 1 : ($point === 'B' ? 2 : match (count($resources)) {
                0 => 5, 1 => 4, default => 3
            }),
            'all' => $all, 'any' => $any, 'expected_resource_types' => $resources,
            'handbook_section' => $section, 'resource_rationale' => $rationale, 'explanation' => $explanation];
    }
}
