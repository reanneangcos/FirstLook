<?php

namespace App\Services\Screening;

use Illuminate\Container\Container;

/** Evidence anchoring verifies provenance, not the semantic accuracy of an LLM interpretation. */
final class NormalizedPatientFacts
{
    public const VERSION = 'patient-concepts-v1';

    public const STATES = ['reported', 'absent', 'unknown', 'not_applicable', 'contradictory'];

    /** @return array<string, string> */
    public static function definitions(): array
    {
        return self::reviewedDefinitions() + (self::researchEnabled() ? ResearchPatientConcepts::definitions() : []);
    }

    public static function version(): string
    {
        return self::researchEnabled() ? ResearchPatientConcepts::VERSION : self::VERSION;
    }

    /** Standalone historical-rule evaluations retain the original vocabulary without requiring Laravel boot. */
    private static function researchEnabled(): bool
    {
        $container = Container::getInstance();

        return $container->bound('config') && $container->make('config')->get('esi.mode') === 'research_prototype';
    }

    /** The original vocabulary is immutable so historical approvals retain their exact scope. */
    public static function reviewedDefinitions(): array
    {
        return [
            'breathing' => 'Current breathing explicitly reported by the patient or proxy.',
            'breathing_without_help' => 'Explicitly breathing without assistance now; not inferred from typing.',
            'responds_to_voice' => 'Explicit current response to voice; not inferred from typing.',
            'responding_normally' => 'Explicit report of responding as usual; not an examination finding.',
            'uncontrolled_bleeding' => 'Reported current uncontrolled bleeding.',
            'chest_discomfort' => 'Reported chest pain, pressure or discomfort.',
            'chest_pressure' => 'Reported pressure sensation in the chest.',
            'pain_radiates_left_arm' => 'Reported pain spreading to the left arm.',
            'nausea' => 'Reported feeling of nausea.',
            'sweating' => 'Reported sweating associated with the complaint.',
            'onset_today' => 'Explicit onset today; do not calculate dates.',
            'sudden_onset' => 'Explicit abrupt onset, not merely recent onset.',
            'one_sided_weakness' => 'Reported weakness on one side.',
            'slurred_speech' => 'Reported slurred speech.',
            'headache' => 'Reported headache.',
            'worst_ever_headache' => 'Explicitly reported worst headache the person has experienced.',
            'previous_headaches' => 'Reported history of headaches; absent only when explicitly denied.',
            'throat_tightness' => 'Reported tightening of the throat.',
            'peanut_exposure' => 'Reported peanut exposure associated with this complaint.',
            'peanut_allergy' => 'Known peanut allergy explicitly reported as history.',
            'fever' => 'Subjective reported fever or feeling feverish, never measured temperature.',
            'current_chemotherapy' => 'Explicit report of currently receiving chemotherapy.',
            'cancer_history' => 'Cancer explicitly reported as known history; never inferred from symptoms.',
            'used_needle_puncture' => 'Reported puncture by a needle just used on another person.',
            'onset_just_now' => 'Explicitly just happened, without inventing a time threshold.',
            'excess_medication_ingestion' => 'Explicit report of taking more than prescribed; no dose calculation.',
            'new_confusion' => 'Reported confusion explicitly described as new.',
            'change_from_mental_baseline' => 'Explicit reported change from the usual mental baseline.',
            'current_suicidal_intent' => 'Explicit current suicidal intent, not a past event or hypothetical mention.',
            'self_harm_thoughts' => 'Reported current self-harm thoughts.',
            'severe_pain' => 'Explicitly described severe pain; a numeric score alone does not establish this concept.',
            'cancer_related_pain' => 'Patient explicitly attributes current pain to known cancer; not model attribution.',
            'unable_to_get_comfortable' => 'Explicit report of being unable to get comfortable.',
            'right_lower_abdominal_pain' => 'Reported pain specifically in the lower right abdomen; unspecified stomach pain is insufficient.',
            'loss_of_appetite' => 'Reported reduced or absent appetite.',
            'known_medical_conditions' => 'Known medical conditions explicitly reported or denied. Unreported is unknown.',
            'onset_this_morning' => 'Explicit onset this morning.',
            'moderate_pain' => 'Explicitly described moderate pain; no numerical-score conversion.',
            'one_sided_lower_leg_pain' => 'Reported pain in one lower leg.',
            'one_sided_lower_leg_swelling' => 'Reported swelling in one lower leg, not observed edema.',
            'prolonged_car_travel' => 'A reported long car journey associated with the complaint; no invented duration cutoff.',
            'ankle_twist' => 'Reported twisting injury to the ankle.',
            'ankle_swelling' => 'Reported ankle swelling, not an examination finding.',
            'pain_on_weight_bearing' => 'Reported pain when putting weight on the affected limb.',
            'mild_pain_or_discomfort' => 'Explicitly described mild pain or discomfort; no numerical-score conversion.',
            'female_reported_sex' => 'Explicitly reported female sex; do not infer from names, pronouns or symptoms.',
            'burning_urination' => 'Reported burning or stinging when urinating.',
            'urinary_frequency' => 'Reported increased frequency of urination.',
            'abdominal_pain' => 'Reported abdominal pain.',
            'flank_pain' => 'Reported flank pain.',
            'vomiting' => 'Reported vomiting.',
            'vaginal_discharge' => 'Reported vaginal discharge.',
            'pregnancy' => 'Pregnancy explicitly reported or denied; never inferred from age or sex.',
            'finger_cut' => 'Reported cut on a finger.',
            'separated_wound_edges' => 'Reported separated wound edges; not an assessed need for sutures.',
            'ongoing_wound_bleeding' => 'Reported continuing bleeding from the described wound.',
            'routine_prescription_refill' => 'Patient explicitly requests a routine refill.',
            'ran_out_regular_bp_medicine' => 'Explicit report of having run out of usual blood-pressure medicine.',
            'hypertension_history' => 'Hypertension explicitly reported as known history; no inferred measurement.',
            'regular_bp_medicine' => 'Explicitly reported regular blood-pressure medication.',
            'pain_or_discomfort' => 'Any pain or discomfort explicitly reported or denied.',
            'dressing_recheck' => 'A request for dressing recheck; not a conclusion that simple care is sufficient.',
            'new_wound_pain' => 'Reported new pain at the wound.',
            'wound_redness' => 'Reported redness at the wound.',
            'wound_swelling' => 'Reported swelling at the wound.',
            'wound_discharge' => 'Reported discharge from the wound.',
            'worsening' => 'Explicit report of worsening or no worsening.',
            'breathing_difficulty' => 'Reported difficulty breathing.',
            'fainting' => 'Reported fainting associated with the current complaint.',
            'severe_distress' => 'Explicitly reported severe distress; not a model judgment of clinical severity.',
            'additional_symptoms' => 'Symptoms beyond the stated complaint; absent requires an explicit scoped denial.',
            'unmapped_symptom' => 'An explicitly reported symptom cannot be represented by this vocabulary. Retain its evidence.',
        ];
    }

    /** @return list<string> */
    public static function sourceFields(): array
    {
        return array_values(array_diff(PatientFields::NAMES, ['age', 'tests_completed', 'tests_requested', 'medical_devices']));
    }

    public function schema(): array
    {
        return ['type' => 'array', 'maxItems' => count(self::definitions()), 'items' => [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['concept', 'state', 'evidence'],
            'properties' => [
                'concept' => ['type' => 'string', 'enum' => array_keys(self::definitions())],
                'state' => ['type' => 'string', 'enum' => self::STATES],
                'evidence' => ['type' => 'array', 'maxItems' => 8, 'items' => [
                    'type' => 'object', 'additionalProperties' => false, 'required' => ['field', 'quote'],
                    'properties' => ['field' => ['type' => 'string', 'enum' => self::sourceFields()],
                        'quote' => ['type' => 'string']],
                ]],
            ],
        ]];
    }

    /** @param list<array<string, mixed>> $extractedFacts */
    public function validate(mixed $normalized, array $extractedFacts): void
    {
        if (! is_array($normalized) || ! array_is_list($normalized) || count($normalized) > count(self::definitions())) {
            throw new InvalidModelOutput('invalid_normalized_facts');
        }
        $sources = array_column($extractedFacts, null, 'field');
        $seen = [];
        foreach ($normalized as $fact) {
            if (! is_array($fact) || count($fact) !== 3
                || ! is_string($fact['concept'] ?? null) || ! isset(self::definitions()[$fact['concept']])
                || in_array($fact['concept'], $seen, true)
                || ! in_array($fact['state'] ?? null, self::STATES, true)
                || ! is_array($fact['evidence'] ?? null) || ! array_is_list($fact['evidence'])
                || count($fact['evidence']) > 8
                || ($fact['state'] !== 'unknown' && $fact['evidence'] === [])) {
                throw new InvalidModelOutput('invalid_normalized_facts');
            }
            $seen[] = $fact['concept'];
            foreach ($fact['evidence'] as $anchor) {
                if (! is_array($anchor) || count($anchor) !== 2
                    || ! in_array($anchor['field'] ?? null, self::sourceFields(), true)
                    || ! is_string($anchor['quote'] ?? null) || trim($anchor['quote']) === '') {
                    throw new InvalidModelOutput('ungrounded_normalized_fact');
                }
                $source = $sources[$anchor['field']] ?? [];
                if (! in_array($source['state'] ?? null, ['reported', 'absent', 'not_applicable'], true)
                    || ! is_string($source['evidence'] ?? null) || ($source['value'] ?? null) !== $source['evidence']
                    || ! str_contains($source['evidence'], $anchor['quote'])
                    || PatientFields::isUnreported($anchor['quote'])
                    || ($source['state'] === 'not_applicable' && $fact['state'] !== 'not_applicable')) {
                    throw new InvalidModelOutput('ungrounded_normalized_fact', $anchor['field']);
                }
            }
        }
    }

    public function instructions(): string
    {
        $version = self::researchEnabled() ? 'normalization-v4-research' : 'normalization-v3';

        return file_get_contents(resource_path('prompts/'.$version.'.txt'))."\n"
            .json_encode(self::definitions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
