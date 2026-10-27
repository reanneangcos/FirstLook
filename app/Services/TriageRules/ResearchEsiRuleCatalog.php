<?php

namespace App\Services\TriageRules;

use App\Services\Screening\NormalizedPatientFacts;

final class ResearchEsiRuleCatalog
{
    public const VERSION = 'research-esi-patient-report-2.0.0';

    public const SOURCES = [
        'handbook' => 'https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf',
        'handbook_v5' => 'https://media.emscimprovement.center/documents/Emergency_Severity_Index_Handbook.pdf',
        'soufi_2018' => 'https://pubmed.ncbi.nlm.nih.gov/29673601/',
        'elias_2015' => 'https://medinform.jmir.org/2015/2/e23/',
        'gaber_2025' => 'https://www.nature.com/articles/s41746-025-01684-1',
        'sharma_2026' => 'https://pubmed.ncbi.nlm.nih.gov/42612553/',
    ];

    /** Only reported danger signals block later profiles; unmeasured negatives remain explicitly unassessed. */
    public const A_SIGNALS = ['unresponsive_now', 'uncontrolled_bleeding', 'active_convulsions', 'blue_colour', 'gasping_or_noisy_breathing'];

    public const B_SIGNALS = ['chest_discomfort', 'breathing_difficulty', 'fainting', 'new_confusion', 'severe_distress',
        'one_sided_weakness', 'new_motor_weakness', 'slurred_speech', 'throat_tightness', 'lip_or_tongue_swelling',
        'swallowing_difficulty', 'current_suicidal_intent', 'self_harm_thoughts', 'excess_medication_ingestion',
        'used_needle_puncture', 'worst_ever_headache', 'vision_change', 'vomiting_blood', 'bloody_stool', 'coughing_blood',
        'saddle_numbness', 'difficulty_urinating', 'recent_seizure', 'severe_pain'];

    /** At decision point C, these reported concepts block a prediction unless a matched profile accounts for them. */
    public const RESOURCE_ACCOUNTED = [
        'RP-C-ABDOMEN' => ['abdominal_pain', 'right_lower_abdominal_pain', 'nausea', 'loss_of_appetite', 'vomiting'],
        'RP-C-FEBRILE-COUGH' => ['cough', 'fever', 'general_weakness', 'loss_of_appetite', 'chills'],
        'RP-C-VOMITING' => ['vomiting', 'repeated_vomiting', 'nausea', 'poor_oral_intake', 'dizziness'],
        'RP-C-DIARRHEA' => ['diarrhea', 'general_weakness', 'orthostatic_lightheadedness', 'dizziness', 'abdominal_pain'],
        'RP-C-LEG-SWELLING' => ['one_sided_lower_leg_pain', 'one_sided_lower_leg_swelling', 'limb_pain', 'limb_swelling'],
        'RP-C-LIMB-INJURY' => ['limb_injury', 'limb_pain', 'limb_swelling', 'ankle_twist', 'ankle_swelling', 'pain_on_weight_bearing'],
        'RP-C-URINARY' => ['burning_urination', 'urinary_frequency', 'urinary_urgency'],
        'RP-C-CUT' => ['skin_cut', 'finger_cut', 'separated_wound_edges', 'bleeding_stopped', 'limb_injury', 'limb_pain'],
        'RP-C-EAR-OBJECT' => ['ear_foreign_body'],
        'RP-C-LOCAL-SKIN' => ['localized_itchy_skin'],
        'RP-C-IMPROVING-URI' => ['cough', 'runny_nose', 'scratchy_throat', 'sneezing', 'watery_eyes'],
        'RP-C-NASAL' => ['runny_nose', 'sneezing', 'watery_eyes', 'scratchy_throat'],
        'RP-C-EYE-PARTICLE' => ['eye_foreign_particle', 'watery_eyes', 'eye_pain'],
    ];

    /** A profile that accounts for a general concept also accounts for its more specific forms. */
    public const SPECIFIC_FORMS = [
        'dizziness' => ['orthostatic_lightheadedness'],
        'abdominal_pain' => ['right_lower_abdominal_pain', 'left_lower_abdominal_pain'],
        'vomiting' => ['repeated_vomiting'],
    ];

    /** Additional reported symptoms that must be accounted for by a matched C profile. */
    public const RESOURCE_EXTRA_SYMPTOMS = ['headache', 'back_pain', 'flank_pain', 'vaginal_discharge', 'generalized_rash', 'numbness', 'eye_pain',
        'ongoing_wound_bleeding', 'burn_injury', 'nosebleed', 'testicular_pain', 'vision_loss', 'eye_injury', 'cannot_open_eye', 'stiff_neck',
        'neck_pain', 'cold_pale_limb', 'memory_loss_of_event', 'loss_of_consciousness', 'heavy_vaginal_bleeding', 'left_lower_abdominal_pain', 'chemical_in_eye'];

    /** A specific report conflicting with an explicit denial of its general concept. */
    public const SPECIFIC_GENERAL_CONFLICTS = [['breathing_without_help', 'breathing'], ['chest_pressure', 'chest_discomfort'],
        ['right_lower_abdominal_pain', 'abdominal_pain'], ['worst_ever_headache', 'headache'],
        ['repeated_vomiting', 'vomiting'], ['current_suicidal_intent', 'self_harm_thoughts']];

    /** Two reports that cannot both be true. */
    public const MUTUALLY_EXCLUSIVE = [['unresponsive_now', 'responding_normally'], ['unresponsive_now', 'responds_to_voice'],
        ['bleeding_stopped', 'uncontrolled_bleeding'], ['symptoms_improving', 'worsening']];

    /** @return list<string> */
    public static function resourceSymptoms(): array
    {
        return array_values(array_unique([...array_merge(...array_values(self::RESOURCE_ACCOUNTED)), ...self::RESOURCE_EXTRA_SYMPTOMS]));
    }

    /** The exact decision-point checks, shared verbatim with Method A's prompt. */
    public static function decisionChecks(): array
    {
        return ['a_danger_signals' => self::A_SIGNALS, 'b_danger_signals' => self::B_SIGNALS,
            'b_signal_exemption' => ['severe_pain is not blocking when limb_injury is reported'],
            'c_blocking_when_reported_or_unknown_with_evidence' => ['unmapped_symptom', 'head_injury'],
            'c_blocking_combination' => ['pregnancy reported with abdominal_pain or burning_urination'],
            'c_symptoms_needing_coverage' => self::resourceSymptoms(), 'c_accounted_by_profile' => self::RESOURCE_ACCOUNTED,
            'c_accounting_includes_specific_forms' => self::SPECIFIC_FORMS,
            'c_worsening' => 'reported worsening blocks when every matched C profile has zero resource types',
            'contradictions' => ['any concept with state contradictory',
                'specific reported while general explicitly absent' => self::SPECIFIC_GENERAL_CONFLICTS,
                'both reported' => self::MUTUALLY_EXCLUSIVE]];
    }

    public function version(): string
    {
        return self::VERSION.'+'.substr(hash('sha256', json_encode([
            $this->definitions(), self::A_SIGNALS, self::B_SIGNALS,
            NormalizedPatientFacts::definitions(),
            hash_file('sha256', __FILE__), hash_file('sha256', __DIR__.'/ResearchEsiRuleLayer.php'),
        ], JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * The catalog-wide review record, accepted only for the exact rule version it names.
     * Any change to definitions, signals, concepts or engine code requires a new record.
     *
     * @return array<string, mixed>|null
     */
    public function approval(): ?array
    {
        $record = config('esi.research_approval');

        return is_array($record) && ($record['status'] ?? null) === 'approved'
            && ($record['rule_version'] ?? null) === $this->version()
            && is_string($record['reviewer'] ?? null) && trim($record['reviewer']) !== ''
            && is_string($record['record_id'] ?? null) && trim($record['record_id']) !== ''
            && is_string($record['reviewed_at'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $record['reviewed_at']) === 1
            ? $record : null;
    }

    /** All mappings are researcher-authored adaptations; approval applies only through a matching catalog review record. */
    public function rules(): array
    {
        $version = $this->version();
        $approval = $this->approval();

        return array_map(function (array $rule) use ($version, $approval): array {
            $concepts = [...array_keys($rule['all']), ...$rule['none']];
            foreach ($rule['any'] as $group) {
                $concepts = [...$concepts, ...array_keys($group)];
            }

            return $rule + [
                'required_concepts' => array_values(array_unique($concepts)),
                'condition' => 'All required facts and at least one fact in each alternative group must match; no listed exclusion may be reported; any minimum age must be met. Earlier reported danger signals take precedence.',
                'required_fields' => ['age', 'patient-report evidence in permitted source fields'],
                'source' => ['handbook' => self::SOURCES['handbook'], 'section' => $rule['handbook_section']],
                'reviewer_approval' => $approval ?? ['status' => 'not_clinically_validated', 'reviewer' => null, 'reviewed_at' => null],
                'approved' => $approval !== null, 'rule_version' => $version,
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
            ], [], 'Chapter 2, Table 2-1, p. 9', 'Uncontrolled bleeding with reported deterioration.'),
            $this->rule('RP-A-CONVULSIONS', 'A', ['active_convulsions' => $r], [
                ['unresponsive_now' => $r, 'blue_colour' => $r],
            ], [], 'ESI v4 Ch. 6, Table 6-2, p. 46 (active seizures); ESI v5 Ch. 3, p. 9', 'Ongoing convulsions with reported unresponsiveness or blue colour.'),
            $this->rule('RP-A-UNRESPONSIVE', 'A', ['unresponsive_now' => $r], [], [], 'ESI v4 Ch. 2, p. 10 (Table 2-2); ESI v5 Ch. 3, p. 9', 'Reported current unresponsiveness.',
                basis: 'v4: "Any patient who is unresponsive ... meets level-1 criteria" (AVPU P or U). v5: signs requiring immediate lifesaving intervention include "unresponsiveness"; "obtunded/unresponsive patient" is an ESI 1 example. A proxy report replaces the AVPU examination.'),
            $this->rule('RP-A-ACTIVE-SEIZURE', 'A', ['active_convulsions' => $r], [], [], 'ESI v5 Ch. 3, p. 9; ESI v4 Ch. 6, Table 6-2, p. 46', 'Reported seizure happening now.',
                basis: 'v5: "Signs of instability requiring immediate, lifesaving intervention include unresponsiveness, active seizure, occluded airway ..." v4 lists "active seizures" as ESI 1 only in its pediatric table and is silent for adults, so v5 is used. A seizure that has already stopped is handled at decision point B.'),
            $this->rule('RP-A-DROOLING', 'A', ['unable_to_swallow_saliva' => $r], [], [], 'ESI v4 Ch. 3, p. 19', 'Reported drooling or inability to swallow saliva.',
                basis: 'v4: "Patients who are drooling and/or striderous may have impending airway loss ... ESI level-1 criteria are met." v5 p. 13 assigns ESI 2; the editions conflict and v4 is followed because the study and its reference labels use v4.'),
            $this->rule('RP-A-CHEST-SHOCK', 'A', ['chest_discomfort' => $r, 'pallor' => $r, 'sweating' => $r], [], [], 'ESI v4 Ch. 2, p. 10; ESI v5 Ch. 3, p. 9', 'Chest pain with reported paleness and sweating.',
                basis: 'v4: chest pain patients "who are pale, diaphoretic, in acute respiratory distress or hemodynamically unstable do meet level-1 criteria." v5 ESI 1 example: "chest pain with signs of hypoperfusion." Paleness and sweating are patient reports, not observed findings.'),
            $this->rule('RP-A-ANAPHYLAXIS', 'A', ['allergen_exposure' => $r], [
                ['fainting' => $r, 'unresponsive_now' => $r, 'blue_colour' => $r],
                ['throat_tightness' => $r, 'lip_or_tongue_swelling' => $r, 'breathing_difficulty' => $r, 'generalized_rash' => $r],
            ], [], 'ESI v4 Ch. 2, p. 11; ESI v5 Ch. 3, p. 9', 'Allergic-type exposure and symptoms with reported collapse or blue colour.',
                basis: 'v4 lists "anaphylactic shock" and v5 lists "anaphylaxis" as ESI 1 examples. Collapse or blue colour is required because v4 triages throat tightening after an exposure without collapse as ESI 2 (RP-B-ALLERGY); the editions differ there.'),
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
            $this->rule('RP-B-NEEDLESTICK', 'B', ['used_needle_puncture' => $r], [], [], 'Chapter 2, p. 12; ESI v5 Ch. 4, p. 12', 'Used-needle exposure.',
                basis: 'Both editions list "a needle stick in a health care worker" as high-risk without a time limit; v5 adds "time sensitive to postexposure prophylaxis treatment." The earlier "just now" requirement was removed because neither edition states it.'),
            $this->rule('RP-B-SEIZURE', 'B', ['recent_seizure' => $r], [], [], 'Chapter 3, p. 20', 'Reported recent seizure.'),
            $this->rule('RP-B-CANCER-PAIN', 'B', ['severe_pain' => $r, 'cancer_related_pain' => $r, 'unable_to_get_comfortable' => $r], [], [], 'Chapter 3, pp. 26–27', 'Contextual severe cancer-related pain; no numerical pain cutoff.'),
            $this->rule('RP-B-CHEST-ISCHEMIA', 'B', [], [['chest_discomfort' => $r, 'chest_pressure' => $r]], [], 'ESI v4 Ch. 3, p. 19; ESI v5 Ch. 4, p. 12', 'Chest pain, pressure or discomfort without a reported cough.',
                none: ['cough'], basis: 'v4: patients "who have an episode of chest or epigastric discomfort, with or without accompanying symptoms ... need to be identified as high-risk ESI level 2." v5 high-risk example: "active chest pain, suspicious for acute coronary syndrome." v4 gives chest pain with cough and fever or respiratory-infection symptoms as the non-ESI-2 exception (p. 19; Table 3-1, p. 23), so a reported cough leaves the case to review.'),
            $this->rule('RP-B-STROKE-SIGNS', 'B', [], [['one_sided_weakness' => $r, 'slurred_speech' => $r, 'facial_droop' => $r]], [], 'ESI v4 Ch. 2, p. 12 and Ch. 3, pp. 20, 27; ESI v5 Ch. 4, p. 12', 'Reported one-sided weakness, slurred speech or facial droop.',
                basis: 'v4 and v5 high-risk example: "signs of a stroke but does not meet level-1 criteria." v4 p. 27: a patient "complaining of left sided weakness ... meets ESI level-2 criteria."'),
            $this->rule('RP-B-MENTAL-STATUS', 'B', [], [['change_from_mental_baseline' => $r, 'new_drowsiness' => $r]], [], 'ESI v4 Ch. 3, p. 26; ESI v5 Ch. 4, p. 12', 'Reported new change in mental state or new drowsiness.',
                basis: 'Decision point B: "new onset confusion, lethargy, or disorientation" (v4 p. 26; v5 p. 12). Chronic confusion does not qualify.'),
            $this->rule('RP-B-HEADACHE-RED-FLAGS', 'B', ['headache' => $r], [[
                'stiff_neck' => $r, 'neck_pain' => $r, 'fever' => $r, 'vomiting' => $r, 'repeated_vomiting' => $r, 'back_pain' => $r,
                'new_confusion' => $r, 'new_drowsiness' => $r, 'change_from_mental_baseline' => $r,
                'one_sided_weakness' => $r, 'slurred_speech' => $r, 'facial_droop' => $r,
            ]], [], 'ESI v5 Ch. 4, p. 12; ESI v4 Ch. 3, p. 20', 'Headache with neck pain or stiffness, fever, vomiting, back pain, a mental-status change or stroke signs.',
                basis: 'v5: "Any headache accompanied by neck pain or nuchal rigidity" warrants immediate evaluation; "other presentations of concern that warrant an ESI 2 designation include headache plus fever, vomiting, lower back pain, altered mental status, and/or signs and symptoms of stroke." v4 p. 20 gives a narrower list.'),
            $this->rule('RP-B-SUICIDAL-THOUGHTS', 'B', ['self_harm_thoughts' => $r], [], [], 'ESI v4 Ch. 3, p. 20 and Table 3-1, p. 24; ESI v5 Ch. 4, pp. 12, 15', 'Reported current thoughts of self-harm or suicide.',
                basis: 'v4 Table 3-1: "22-year-old male with suicidal thoughts" is ESI 2. v5 lists "suicidal ideation, plan, or attempt" as severe distress, and "patients who are suicidal, homicidal, psychotic, or violent should be assigned an ESI level of 2."'),
            $this->rule('RP-B-DANGER-TO-OTHERS', 'B', [], [['thoughts_of_harming_others' => $r, 'hallucinations' => $r]], [], 'ESI v4 Ch. 3, p. 20; ESI v5 Ch. 4, p. 15', 'Reported thoughts of harming others or hallucinations.',
                basis: '"Patients who are suicidal, homicidal, psychotic, or violent should be assigned an ESI level of 2" (v5); v4 calls them high-risk.'),
            $this->rule('RP-B-SEVERE-DISTRESS-EVENT', 'B', [], [['sexual_assault' => $r, 'domestic_violence' => $r, 'pregnancy_loss' => $r]], [], 'ESI v4 Ch. 3, p. 27; ESI v5 Ch. 4, pp. 12, 15', 'Reported recent sexual assault, domestic violence or pregnancy loss.',
                basis: 'Severe psychological distress examples: "distraught after experiencing a sexual assault," "survivor of domestic violence," and "prenatal loss" (v5 p. 12; v4 p. 27). v5 also lists sexual assault as a high-risk situation and mechanism.'),
            $this->rule('RP-B-RENAL-COLIC', 'B', ['flank_pain' => $r, 'severe_pain' => $r], [], [], 'ESI v4 Ch. 3, pp. 26–27; ESI v5 Ch. 4, pp. 12, 14', 'Explicitly severe flank pain.',
                basis: 'v5: "Patients presenting with severe flank pain receive a level-2 designation"; renal colic, cancer or sickle cell pain "should be triaged as ESI level 2" (v4 and v5). Severity must be described in words, not inferred from a number.'),
            $this->rule('RP-B-URINARY-RETENTION', 'B', ['unable_to_pass_urine' => $r], [], [], 'ESI v4 Ch. 3, p. 27', 'Reported inability to pass urine.',
                basis: 'v4 severe-pain examples justifying ESI 2 include "females, and more commonly males, with acute urinary retention."'),
            $this->rule('RP-B-UROSEPSIS-SIGNS', 'B', [], [
                ['burning_urination' => $r, 'urinary_frequency' => $r, 'urinary_urgency' => $r],
                ['back_pain' => $r, 'flank_pain' => $r, 'chills' => $r],
            ], [], 'ESI v5 Ch. 4, p. 14', 'Urinary symptoms with back or flank pain or chills.',
                basis: 'v5: "The patient with symptoms of a UTI who ... also complains of back pain, chills, and rigors may have urosepsis and should also be assigned to ESI 2."'),
            $this->rule('RP-B-SICKLE-CELL-PAIN', 'B', ['sickle_cell_disease' => $r], [
                ['pain_or_discomfort' => $r, 'severe_pain' => $r, 'moderate_pain' => $r, 'limb_pain' => $r, 'back_pain' => $r, 'chest_discomfort' => $r, 'abdominal_pain' => $r],
            ], [], 'ESI v4 Ch. 3, pp. 26–27; ESI v5 Ch. 4, p. 12', 'Pain in a person with known sickle cell disease.',
                basis: 'Pain from "renal colic, cancer, or sickle cell crisis, should be triaged as ESI level 2" (v5; same in v4).'),
            $this->rule('RP-B-BURN-BLISTERING', 'B', ['burn_injury' => $r, 'burn_blistering' => $r], [], [], 'ESI v4 Ch. 3, pp. 19, 27', 'Burn with reported blistering or deeper skin damage.',
                basis: 'v4: "any full- or partial-thickness burn that will require immediate pain control" (p. 27); third-degree burns are high-risk (p. 19). Blistering is the patient-reportable sign of at least partial thickness.'),
            $this->rule('RP-B-OLDER-ADULT-ABDOMEN', 'B', ['abdominal_pain' => $r, 'severe_pain' => $r], [], [], 'ESI v4 Ch. 3, p. 18 and Table 3-1, p. 23; ESI v5 Ch. 4, pp. 11, 14', 'Explicitly severe abdominal pain at age 65 or older.',
                minAge: 65, basis: 'v4: "The elderly patient with abdominal pain should be classified as ESI level 2" (example: frail elderly with severe abdominal pain). v5 notes older patients with abdominal pain are the most under-triaged and likely to need hospitalization; 65 follows v5 p. 11. Severe pain is required because v5 says ESI 2 versus 3 may depend on vital signs.'),
            $this->rule('RP-B-PENETRATING-INJURY', 'B', ['penetrating_injury' => $r], [], [], 'ESI v4 Ch. 3, pp. 21–22 and Table 3-1, p. 25; ESI v5 Ch. 4, p. 15', 'Reported stab or gunshot wound.',
                basis: 'v4: high-risk injuries include "gun shot or stab wound victims"; Table 3-1: stab wound to the groin with bleeding controlled is ESI 2. v5: "penetrating trauma of the head, neck, chest, and abdomen without signs of hemodynamic instability" may warrant ESI 2.'),
            $this->rule('RP-B-HIGH-RISK-MECHANISM', 'B', [], [['fall_from_height' => $r, 'vehicle_ejection_or_entrapment' => $r, 'struck_by_vehicle' => $r]], [], 'ESI v5 Ch. 4, p. 15; ESI v4 Ch. 3, pp. 21–22', 'High-energy injury mechanism.',
                basis: 'v5: "Mechanisms of injury that warrant an ESI 2 designation include falls of 20 feet (6 meters) or more, ejection from a vehicle, or removal from a vehicle with mechanical extrication tools." v4: high-risk mechanisms include "pedestrian struck by a car."'),
            $this->rule('RP-B-HEAD-INJURY-RED-FLAGS', 'B', ['head_injury' => $r], [
                ['loss_of_consciousness' => $r, 'fainting' => $r, 'memory_loss_of_event' => $r, 'vomiting' => $r, 'repeated_vomiting' => $r, 'new_confusion' => $r, 'new_drowsiness' => $r],
            ], [], 'ESI v4 Ch. 3, p. 22 and Table 3-1, p. 25', 'Head injury with loss of consciousness, memory loss, vomiting, confusion or drowsiness.',
                basis: 'v4 trauma details include "loss of consciousness" and "head injured patient ... with symptoms of increase intracranial pressure (headache/vomiting)"; Table 3-1: crash with amnesia, and fall with loss of consciousness and vomiting, are ESI 2. Head injury without these signs remains Needs Review.'),
            $this->rule('RP-B-NOSEBLEED-BLEEDING-RISK', 'B', ['nosebleed' => $r], [['anticoagulant_use' => $r, 'bleeding_disorder' => $r]], [], 'ESI v4 Ch. 3, p. 19; ESI v5 Ch. 4, p. 13', 'Nosebleed while taking an anticoagulant or with a known bleeding disorder.',
                basis: 'v5: high-risk epistaxis includes bleeding in a patient "with known thrombocytopenia, clotting dyscrasias, and use of warfarin or other anti-coagulants"; "In these situations, patients are assigned an ESI level of 2." v4 is the same for anticoagulants.'),
            $this->rule('RP-B-PREGNANCY-SYMPTOMS', 'B', ['pregnancy' => $r], [[
                'chest_discomfort' => $r, 'breathing_difficulty' => $r,
                'right_lower_abdominal_pain' => $r, 'left_lower_abdominal_pain' => $r, 'headache' => $r,
            ]], [], 'ESI v4 Ch. 2, p. 12, Ch. 3, p. 20 and Table 3-1, p. 24; ESI v5 Ch. 4, p. 14', 'Reported pregnancy with chest pain, breathlessness, one-sided lower abdominal pain or headache.',
                basis: 'v4: pregnant patients "with localized abdominal pain" are ESI 2 and "generalized cramping and bleeding with stable vital signs do not meet ESI level-2 criteria" (p. 20). v5 adds "chest pain and/or shortness of breath ... or headache," about which v4 is silent. For generalized abdominal pain the editions conflict and v4 is followed, so only one-sided lower abdominal pain matches.'),
            $this->rule('RP-B-FEMALE-LOWER-QUADRANT', 'B', ['female_reported_sex' => $r, 'severe_pain' => $r], [['right_lower_abdominal_pain' => $r, 'left_lower_abdominal_pain' => $r]], [], 'ESI v4 Table 3-1, p. 24; ESI v5 Ch. 4, p. 14', 'Explicitly severe one-sided lower abdominal pain in a female patient.',
                basis: 'v4 Table 3-1: "24-year-old female with severe left lower quadrant pain abdominal, denies vaginal bleeding" is ESI 2, "high risk for ectopic pregnancy, unless the triage nurse can confirm the absence of pregnancy." v5: unilateral lower quadrant pain "should be evaluated for ovarian torsion and ectopic pregnancy." A patient\'s pregnancy denial is not nurse confirmation, so it does not exclude the rule. Non-severe pain stays with the resource profiles.'),
            $this->rule('RP-B-POSTPARTUM-BLEEDING', 'B', ['recent_childbirth' => $r, 'heavy_vaginal_bleeding' => $r], [], [], 'ESI v4 Ch. 3, p. 21; ESI v5 Ch. 4, pp. 12, 14', 'Heavy vaginal bleeding after recent childbirth.',
                basis: '"Postpartum patients presenting with heavy vaginal bleeding should also be assigned ESI level 2" (v5; same in v4).'),
            $this->rule('RP-B-TESTICULAR', 'B', ['testicular_pain' => $r], [], [], 'ESI v5 Ch. 4, p. 14; ESI v4 Ch. 3, p. 20 and Table 3-1, p. 24', 'Reported testicular or scrotal pain.',
                basis: 'v5: "Testicular or scrotal pain should be immediately evaluated for testicular torsion, a time-sensitive clinical situation capable of producing permanent organ loss." v4 Table 3-1: sudden severe testicle pain is ESI 2.'),
            $this->rule('RP-B-SUDDEN-VISION-LOSS', 'B', ['vision_loss' => $r, 'sudden_onset' => $r], [], [], 'ESI v4 Ch. 3, p. 21 and Table 3-1, p. 23', 'Sudden partial or complete loss of vision.',
                basis: 'v4: patients with "sudden partial or full loss of vision ... should be triaged at ESI level 2"; "all complaints with sudden loss of vision are high-risk."'),
            $this->rule('RP-B-CHEMICAL-EYE', 'B', ['chemical_in_eye' => $r], [], [], 'ESI v4 Ch. 3, p. 21 and Ch. 4, p. 31', 'Reported chemical splash to the eye.',
                basis: 'v4: a chemical splash to the eye "constitutes a very high priority level-2 patient."'),
            $this->rule('RP-B-EYE-TRAUMA', 'B', ['eye_injury' => $r], [['vision_change' => $r, 'vision_loss' => $r, 'cannot_open_eye' => $r]], [], 'ESI v4 Ch. 3, p. 21 and Table 3-1, p. 23', 'Eye injury with a change in vision or inability to open the eye.',
                basis: 'v4: patients "with significant trauma to the eye ... should be triaged at ESI level 2"; Table 3-1: trauma to the eye, "unable to open eye," is ESI 2.'),
            $this->rule('RP-B-POISONING', 'B', ['poison_ingestion' => $r], [], [], 'ESI v4 Ch. 3, p. 22; ESI v5 Ch. 4, p. 15', 'Reported ingestion of a poison or toxic substance.',
                basis: 'v5: "Toxic ingestion is a high-risk presentation which needs to be rapidly evaluated." v4: most overdoses "represent a high-risk situation."'),
            $this->rule('RP-B-DIABETIC-VOMITING', 'B', ['diabetes_history' => $r], [['vomiting' => $r, 'repeated_vomiting' => $r]], [], 'ESI v4 Table 3-1, p. 23', 'Vomiting in a person with known diabetes.',
                basis: 'v4 Table 3-1: "40-year-old female diabetic with vomiting for 2 days" is ESI 2, "at high risk for diabetic ketoacidosis."'),
            $this->rule('RP-B-DIALYSIS', 'B', ['dialysis_patient' => $r], [['general_weakness' => $r, 'dizziness' => $r, 'missed_dialysis' => $r]], [], 'ESI v4 Ch. 3, p. 20 and Table 3-1, p. 23', 'Dialysis patient with weakness, dizziness or missed dialysis.',
                basis: 'v4 Table 3-1: a regular dialysis patient "who is weak and dizzy" is ESI 2 (hyperkalemia risk); dialysis patients "unable to complete dialysis" are high-risk.'),
            $this->rule('RP-B-TRANSPLANT-FEVER', 'B', ['organ_transplant' => $r, 'fever' => $r], [], [], 'ESI v5 Ch. 4, pp. 12, 15; ESI v4 Ch. 3, p. 22', 'Fever after an organ transplant.',
                basis: 'v5: transplant patients "presenting with a fever or other indication of infection or rejection should be designated ESI level 2." v4: a transplant patient with an unrelated minor problem is not automatically ESI 2.'),
            $this->rule('RP-B-VALVE-FEVER', 'B', ['heart_valve_replacement' => $r, 'fever' => $r], [], [], 'ESI v4 Ch. 3, p. 19', 'Fever after a heart valve replacement.',
                basis: 'v4: other high-risk cardiovascular situations include "patients who present with a fever post valve replacement."'),
            $this->rule('RP-B-ACUTE-LIMB-ISCHEMIA', 'B', ['cold_pale_limb' => $r], [['sudden_onset' => $r, 'limb_injury' => $r]], [], 'ESI v4 Ch. 3, pp. 19, 21 and Table 3-1, p. 23; ESI v5 Ch. 4, p. 15', 'Suddenly cold, pale or blue limb, or after injury.',
                basis: 'v4: "acute vascular arterial occlusions" are high-risk; Table 3-1: sudden foot pain with the foot "cold to touch" is ESI 2. Both editions: "any extremity injury with compromised neurovascular function" is high-risk.'),
            $this->rule('RP-B-LIMB-NEUROVASCULAR', 'B', ['limb_injury' => $r, 'numbness' => $r], [], [], 'ESI v4 Ch. 3, p. 21; ESI v5 Ch. 4, p. 15', 'Limb injury with reported numbness.',
                basis: 'Both editions: high-risk orthopedic injuries include "any extremity injury with compromised neurovascular function." Reported numbness replaces a neurovascular examination.'),
            $this->rule('RP-B-AMPUTATION', 'B', ['amputation' => $r], [], [], 'ESI v4 Ch. 3, pp. 21–22; ESI v5 Ch. 4, p. 15', 'Reported partial or complete amputation.',
                basis: 'Both editions: high-risk injuries include "partial or complete amputations."'),

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
            $this->rule('RP-C-EYE-PARTICLE', 'C', ['eye_foreign_particle' => $r], [], ['simple_procedure'], 'ESI v4 Ch. 4, p. 31', 'Dust or a small particle in the eye.',
                'Eye irrigation counts as one resource; the eye examination does not.', none: ['chemical_in_eye', 'eye_injury', 'vision_loss'],
                basis: '"If the eye problem was due to dust particles in the eye ... the eye irrigation would count as a resource and the patient would meet ESI level-4 criteria."'),
        ];
    }

    /**
     * @param  array<string, string>  $all
     * @param  list<array<string, string>>  $any
     * @param  list<string>  $resources
     * @param  list<string>  $none  Reported concepts that prevent the profile from matching.
     */
    private function rule(string $id, string $point, array $all, array $any, array $resources, string $section, string $explanation,
        ?string $rationale = null, array $none = [], ?int $minAge = null, ?string $basis = null): array
    {
        return ['rule_id' => $id, 'decision_point' => $point,
            'priority' => $point === 'A' ? 1 : ($point === 'B' ? 2 : match (count($resources)) {
                0 => 5, 1 => 4, default => 3
            }),
            'all' => $all, 'any' => $any, 'none' => $none, 'min_age' => $minAge, 'expected_resource_types' => $resources,
            'handbook_section' => $section, 'handbook_basis' => $basis, 'resource_rationale' => $rationale, 'explanation' => $explanation];
    }
}
