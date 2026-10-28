<?php

namespace Tests\Feature;

use App\Services\Screening\NormalizedPatientFacts;
use App\Services\Screening\StudyProtocol;
use App\Services\TriageRules\ProvisionalEsiRuleCatalog;
use App\Services\TriageRules\ResearchEsiRuleCatalog;
use App\Services\TriageRules\ResearchEsiRuleLayer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResearchEsiRuleLayerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['esi.mode' => 'research_prototype']);
        /** Simulates a review record for the current catalog; the shipped record covers only the version the nurse reviewed. */
        config(['esi.research_approval.rule_version' => (new ResearchEsiRuleCatalog)->version()]);
    }

    #[DataProvider('presentations')]
    public function test_supported_profiles_produce_auditable_provisional_predictions(string $text, array $states, int $priority, string $rule): void
    {
        [$facts, $concepts] = $this->facts($text, $states);
        $engine = app(ResearchEsiRuleLayer::class);
        $result = $engine->evaluate($facts, [], $concepts);
        $this->assertSame($priority, $result['priority'], $result['explanation']);
        $this->assertSame('classified', $result['status']);
        $this->assertContains($rule, $result['matched_rule_ids']);
        $this->assertSame('not_assessed', $result['decision_d']['status']);
        $this->assertSame($result, $engine->evaluate($facts, [], $concepts));
        $selected = array_values(array_filter($result['trace'], fn (array $r): bool => $r['rule_id'] === $rule))[0];
        $this->assertNotEmpty($selected['matched_evidence']);
        $this->assertNotEmpty($selected['source']['section']);
        $this->assertFalse($selected['approval_enforced']);
        $this->assertTrue($selected['approved']);
        $this->assertSame('Abigail Rose Gayatin-Sabandal, PHRN, USRN', $selected['reviewer_approval']['reviewer']);
        $this->assertSame('approved', $result['reviewer_approval_status']);
        $this->assertContains('nurse_reviewed_research_adaptation', $result['flags']);
    }

    public function test_review_record_for_another_rule_version_does_not_approve_the_catalog(): void
    {
        $catalog = new ResearchEsiRuleCatalog;
        $this->assertSame($catalog->version(), config('esi.research_approval.rule_version'));
        $this->assertTrue(app(StudyProtocol::class)->readiness()['ready']);
        $this->assertSame('nurse_reviewed_research_adaptation', app(StudyProtocol::class)->criteriaStatus());

        config(['esi.research_approval.rule_version' => 'research-esi-patient-report-2.0.0+772a55351e054556']);
        $this->assertNull($catalog->approval());
        $this->assertFalse(array_any($catalog->rules(), fn (array $rule): bool => $rule['approved']));
        $this->assertSame('not_clinically_validated', $catalog->rules()[0]['reviewer_approval']['status']);
        $readiness = app(StudyProtocol::class)->readiness();
        $this->assertFalse($readiness['ready']);
        $this->assertStringContainsString('No nurse review record matches', implode(' ', $readiness['blockers']));
        $this->assertSame('unvalidated_research_adaptation', app(StudyProtocol::class)->criteriaStatus());
        [$facts, $concepts] = $this->facts('Refill, otherwise feeling well.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported']);
        $this->assertSame('not_clinically_validated', app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts)['reviewer_approval_status']);
    }

    public static function presentations(): array
    {
        return [
            'no breathing' => ['She is not breathing now.', ['breathing' => 'absent'], 1, 'RP-A-APNEA'],
            'airway' => ['He will not wake up and is gasping.', ['unresponsive_now' => 'reported', 'gasping_or_noisy_breathing' => 'reported'], 1, 'RP-A-AIRWAY'],
            'bleeding' => ['Bleeding will not stop and he is turning pale.', ['uncontrolled_bleeding' => 'reported', 'pallor' => 'reported'], 1, 'RP-A-BLEEDING'],
            'convulsions' => ['She is still shaking all over and is blue around the mouth.', ['active_convulsions' => 'reported', 'blue_colour' => 'reported'], 1, 'RP-A-CONVULSIONS'],
            'chest' => ['My chest hurts and I feel nauseous.', ['chest_discomfort' => 'reported', 'nausea' => 'reported'], 2, 'RP-B-CHEST'],
            'neurological' => ['Suddenly my words are slurred.', ['sudden_onset' => 'reported', 'slurred_speech' => 'reported'], 2, 'RP-B-NEUROLOGICAL'],
            'oncology fever' => ['I have a fever while receiving chemotherapy.', ['fever' => 'reported', 'current_chemotherapy' => 'reported'], 2, 'RP-B-CHEMOTHERAPY'],
            'abdomen' => ['My belly hurts and I feel nauseous.', ['abdominal_pain' => 'reported', 'nausea' => 'reported'], 3, 'RP-C-ABDOMEN'],
            'cough fever' => ['I have a cough, fever and feel weak.', ['cough' => 'reported', 'fever' => 'reported', 'general_weakness' => 'reported'], 3, 'RP-C-FEBRILE-COUGH'],
            'urinary' => ['Burning and frequent urination, no fever.', ['burning_urination' => 'reported', 'urinary_frequency' => 'reported', 'fever' => 'absent'], 4, 'RP-C-URINARY'],
            'injury' => ['I twisted my knee; it hurts but I can walk.', ['limb_injury' => 'reported', 'limb_pain' => 'reported', 'preserved_limb_function' => 'reported'], 4, 'RP-C-LIMB-INJURY'],
            'ear' => ['An earbud tip is stuck in my ear.', ['ear_foreign_body' => 'reported'], 4, 'RP-C-EAR-OBJECT'],
            'refill' => ['I need a routine refill and feel normal.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported'], 5, 'RP-C-REFILL'],
            'skin' => ['A few itchy bites on my legs; I feel fine.', ['localized_itchy_skin' => 'reported', 'feels_well' => 'reported'], 5, 'RP-C-LOCAL-SKIN'],
            'unresponsive alone' => ['My father does not answer when we call him.', ['unresponsive_now' => 'reported'], 1, 'RP-A-UNRESPONSIVE'],
            'active seizure' => ['She is shaking all over right now.', ['active_convulsions' => 'reported'], 1, 'RP-A-ACTIVE-SEIZURE'],
            'chest with paleness and sweating' => ['Chest pain, I am pale and sweating.', ['chest_discomfort' => 'reported', 'pallor' => 'reported', 'sweating' => 'reported'], 1, 'RP-A-CHEST-SHOCK'],
            'anaphylaxis with collapse' => ['After a bee sting my lips swelled and I fainted.', ['allergen_exposure' => 'reported', 'lip_or_tongue_swelling' => 'reported', 'fainting' => 'reported'], 1, 'RP-A-ANAPHYLAXIS'],
            'chest pain alone' => ['My chest feels tight.', ['chest_discomfort' => 'reported'], 2, 'RP-B-CHEST-ISCHEMIA'],
            'facial droop' => ['One side of my face is drooping.', ['facial_droop' => 'reported'], 2, 'RP-B-STROKE-SIGNS'],
            'headache with stiff neck' => ['Headache and my neck is stiff.', ['headache' => 'reported', 'stiff_neck' => 'reported'], 2, 'RP-B-HEADACHE-RED-FLAGS'],
            'suicidal thoughts' => ['I keep thinking of ending my life.', ['self_harm_thoughts' => 'reported'], 2, 'RP-B-SUICIDAL-THOUGHTS'],
            'severe flank pain' => ['Severe pain in my side.', ['flank_pain' => 'reported', 'severe_pain' => 'reported'], 2, 'RP-B-RENAL-COLIC'],
            'urinary with chills' => ['Burning urine with chills.', ['burning_urination' => 'reported', 'chills' => 'reported'], 2, 'RP-B-UROSEPSIS-SIGNS'],
            'pregnant headache' => ['I am pregnant and have a headache.', ['pregnancy' => 'reported', 'headache' => 'reported'], 2, 'RP-B-PREGNANCY-SYMPTOMS'],
            'testicular pain' => ['Pain in my testicle.', ['testicular_pain' => 'reported'], 2, 'RP-B-TESTICULAR'],
            'high fall' => ['I fell from the roof of a two-storey house.', ['fall_from_height' => 'reported'], 2, 'RP-B-HIGH-RISK-MECHANISM'],
            'head injury with blackout' => ['Hit my head and blacked out.', ['head_injury' => 'reported', 'loss_of_consciousness' => 'reported'], 2, 'RP-B-HEAD-INJURY-RED-FLAGS'],
            'nosebleed on warfarin' => ['Nosebleed; I take warfarin.', ['nosebleed' => 'reported', 'anticoagulant_use' => 'reported'], 2, 'RP-B-NOSEBLEED-BLEEDING-RISK'],
            'transplant fever' => ['Kidney transplant last year and now a fever.', ['organ_transplant' => 'reported', 'fever' => 'reported'], 2, 'RP-B-TRANSPLANT-FEVER'],
            'dust in eye' => ['Dust blew into my eye.', ['eye_foreign_particle' => 'reported'], 4, 'RP-C-EYE-PARTICLE'],
            'blue lips and breathless' => ['I cannot breathe and my lips look blue.', ['breathing_difficulty' => 'reported', 'blue_colour' => 'reported'], 1, 'RP-A-RESPIRATORY-DISTRESS'],
            'choking' => ['Food is stuck in his throat and he cannot breathe.', ['choking' => 'reported'], 1, 'RP-A-CHOKING'],
            'severe distress' => ['I am in severe distress and unsafe at home.', ['severe_distress' => 'reported'], 2, 'RP-B-SEVERE-PAIN-DISTRESS'],
            'fainting' => ['I fainted twice today.', ['fainting' => 'reported'], 2, 'RP-B-FAINTING'],
            'black stool and dizzy' => ['Black stool and I feel dizzy.', ['bloody_stool' => 'reported', 'dizziness' => 'reported'], 2, 'RP-B-GI-BLEEDING'],
            'vomiting and dry mouth' => ['Vomiting and diarrhea, dry mouth and weak.', ['vomiting' => 'reported', 'diarrhea' => 'reported', 'dry_mouth' => 'reported', 'general_weakness' => 'reported'], 3, 'RP-C-GI-FLUID-LOSS'],
            'blood in urine' => ['Blood in my urine and back pain.', ['blood_in_urine' => 'reported', 'back_pain' => 'reported'], 3, 'RP-C-BLOOD-IN-URINE'],
            'swollen joint' => ['My knee is painful, swollen and warm.', ['limb_pain' => 'reported', 'limb_swelling' => 'reported', 'localized_warmth' => 'reported'], 3, 'RP-C-JOINT-SWELLING'],
            'red leg with fever' => ['My leg is red and warm and I feel feverish.', ['skin_redness' => 'reported', 'localized_warmth' => 'reported', 'fever' => 'reported'], 3, 'RP-C-SKIN-INFECTION'],
            'deep cut' => ['Deep cut on my hand from glass.', ['skin_cut' => 'reported', 'deep_wound' => 'reported'], 3, 'RP-C-DEEP-WOUND'],
            'cut needing care' => ['Cut on my palm; bleeding stopped.', ['skin_cut' => 'reported', 'bleeding_stopped' => 'reported'], 4, 'RP-C-WOUND-REPAIR'],
            'eyebrow cut' => ['Cut above my eyebrow after bumping a door.', ['skin_cut' => 'reported', 'head_injury' => 'reported'], 4, 'RP-C-FACE-CUT'],
            'sore ribs' => ['Fell and hurt my ribs; they are bruised.', ['torso_injury' => 'reported', 'bruising' => 'reported'], 4, 'RP-C-TORSO-INJURY'],
            'urinary with fever' => ['Burning urination and a fever.', ['burning_urination' => 'reported', 'fever' => 'reported'], 4, 'RP-C-URINARY-FEVER'],
            'sore throat with fever' => ['Sore throat and fever.', ['scratchy_throat' => 'reported', 'fever' => 'reported'], 4, 'RP-C-SORE-THROAT-FEVER'],
            'injury with bruise' => ['Twisted ankle, painful and bruised.', ['limb_injury' => 'reported', 'limb_pain' => 'reported', 'bruising' => 'reported'], 4, 'RP-C-LIMB-INJURY'],
            'paper cut' => ['A very small paper cut.', ['skin_cut' => 'reported', 'minor_wound' => 'reported'], 5, 'RP-C-MINOR-CUT'],
            'bruise' => ['Small bruise on my arm, a bit tender.', ['bruising' => 'reported', 'tenderness' => 'reported'], 5, 'RP-C-BRUISE'],
            'boil' => ['Small boil on my forearm.', ['skin_lump' => 'reported'], 5, 'RP-C-SKIN-LUMP'],
            'sunburn' => ['Mild sunburn, skin is red.', ['burn_injury' => 'reported', 'skin_redness' => 'reported'], 5, 'RP-C-MINOR-BURN'],
            'cat bite' => ['A cat bit my hand; it is a bit swollen.', ['animal_bite' => 'reported', 'limb_swelling' => 'reported'], 5, 'RP-C-ANIMAL-BITE'],
            'sore throat' => ['Mild sore throat and a stuffy nose.', ['scratchy_throat' => 'reported', 'nasal_congestion' => 'reported'], 5, 'RP-C-SORE-THROAT'],
            'back strain' => ['Lower back ache after sitting; muscles feel tight.', ['back_pain' => 'reported', 'muscle_stiffness' => 'reported'], 5, 'RP-C-BACK-STRAIN'],
            'dry itchy skin' => ['Dry itchy skin with peeling.', ['localized_itchy_skin' => 'reported', 'skin_dryness' => 'reported'], 5, 'RP-C-LOCAL-SKIN'],
            'watery eyes and sneezing' => ['Watery eyes and sneezing.', ['watery_eyes' => 'reported', 'sneezing' => 'reported'], 5, 'RP-C-NASAL'],
        ];
    }

    public function test_missing_negatives_remain_missing_without_blocking_a_complete_research_profile(): void
    {
        [$facts, $concepts] = $this->facts('Refill, otherwise feeling well.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported']);
        $result = app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts);
        $this->assertSame(5, $result['priority']);
        $this->assertSame('missing', $result['unassessed_danger_signs']['breathing']);
        $this->assertSame('missing', $result['unassessed_danger_signs']['chest_discomfort']);
        $this->assertFalse($result['clinically_validated']);
    }

    public function test_earlier_danger_overrides_resources_and_incomplete_danger_blocks_them(): void
    {
        [$facts, $concepts] = $this->facts('Refill, but my chest hurts and I feel nauseous.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported', 'chest_discomfort' => 'reported', 'nausea' => 'reported']);
        $engine = app(ResearchEsiRuleLayer::class);
        $this->assertSame(2, $engine->evaluate($facts, [], $concepts)['priority']);
        [$facts, $concepts] = $this->facts('Refill, but I vomited blood.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported', 'vomiting_blood' => 'reported']);
        $result = $engine->evaluate($facts, [], $concepts);
        $this->assertNull($result['priority']);
        $this->assertSame('B', $result['stopped_at']);
        $concepts[] = ['concept' => 'breathing', 'state' => 'absent', 'evidence' => [['field' => 'symptom_description', 'quote' => $facts[1]['evidence']]]];
        $this->assertSame(1, $engine->evaluate($facts, [], $concepts)['priority']);
    }

    public function test_explicit_uncertainty_about_danger_is_not_treated_as_unmentioned(): void
    {
        [$facts, $concepts] = $this->facts('Refill. I am unsure whether the chest feeling is pain.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported', 'chest_discomfort' => 'unknown']);
        $this->assertNull(app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts)['priority']);
    }

    public function test_resource_profile_requires_its_explicit_negative_and_preserves_distinct_states(): void
    {
        foreach (['missing' => null, 'unknown' => null, 'not_applicable' => null, 'reported' => 4] as $state => $priority) {
            $states = ['burning_urination' => 'reported', 'urinary_frequency' => 'reported'];
            if ($state !== 'missing') {
                $states['fever'] = $state;
            }
            [$facts, $concepts] = $this->facts('Urinary symptoms; fever status recorded separately.', $states);
            $result = app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts);
            $this->assertSame($priority, $result['priority'], $state);
            $rule = array_values(array_filter($result['trace'], fn (array $r): bool => $r['rule_id'] === 'RP-C-URINARY'))[0];
            $observation = array_values(array_filter($rule['observations'], fn (array $o): bool => $o['concept'] === 'fever'))[0];
            $this->assertSame($state, $observation['state']);
        }
    }

    public function test_unrelated_symptoms_and_contradictions_prevent_low_resource_predictions(): void
    {
        foreach (['headache', 'unmapped_symptom', 'head_injury'] as $extra) {
            [$facts, $concepts] = $this->facts('Refill with another current symptom.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported', $extra => 'reported']);
            $this->assertNull(app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts)['priority']);
        }
        [$facts, $concepts] = $this->facts('Conflicting current reports.', ['breathing' => 'absent', 'breathing_without_help' => 'reported']);
        $result = app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts);
        $this->assertSame('input', $result['stopped_at']);
        $this->assertNull($result['priority']);
    }

    public function test_two_resource_profiles_combine_types_without_counting_tests_or_devices(): void
    {
        [$facts, $concepts] = $this->facts('Urinary burning and frequency, no fever; also an injured painful swollen knee.', ['burning_urination' => 'reported', 'urinary_frequency' => 'reported', 'fever' => 'absent', 'limb_injury' => 'reported', 'limb_pain' => 'reported', 'limb_swelling' => 'reported']);
        $facts[] = ['field' => 'tests_requested', 'state' => 'reported', 'value' => 'MRI and blood tests', 'evidence' => 'MRI and blood tests'];
        $result = app(ResearchEsiRuleLayer::class)->evaluate($facts, [], $concepts);
        $this->assertSame(3, $result['priority']);
        $this->assertSame(2, $result['expected_resources']['count_band']);
        $this->assertSame(['imaging', 'laboratory'], $result['expected_resources']['types']);
        $this->assertStringContainsString('nurse evaluation and vital signs required', $result['display_label']);
        $this->assertNull(app(ResearchEsiRuleLayer::class)->evaluate($facts, [], [])['priority']);
    }

    public function test_bad_provenance_pediatric_scope_and_numeric_pain_alone_do_not_classify(): void
    {
        [$facts, $concepts] = $this->facts('Needs a refill and feels fine.', ['routine_prescription_refill' => 'reported', 'feels_well' => 'reported']);
        $engine = app(ResearchEsiRuleLayer::class);
        $concepts[0]['evidence'][0]['quote'] = 'invented';
        $this->assertNull($engine->evaluate($facts, [], $concepts)['priority']);
        [$facts, $concepts] = $this->facts('Pain 10/10.', []);
        $this->assertNull($engine->evaluate($facts, [], $concepts)['priority']);
        $facts[0]['value'] = $facts[0]['evidence'] = '12';
        $this->assertSame('scope', $engine->evaluate($facts, [], $concepts)['stopped_at']);
        $this->assertNull($engine->evaluate($facts, ['priority' => 1], $concepts)['priority']);
    }

    public function test_catalog_has_all_levels_unique_ids_and_does_not_inherit_reviewed_approvals(): void
    {
        $rules = app(ResearchEsiRuleCatalog::class)->rules();
        $this->assertCount(92, $rules);
        $this->assertCount(92, array_unique(array_column($rules, 'rule_id')));
        $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], array_values(array_unique(array_column($rules, 'priority'))));
        $old = new ProvisionalEsiRuleCatalog(config('esi.provisional_rules'), config('esi.provisional_approvals'));
        $this->assertCount(18, array_filter($old->rules(), fn (array $r): bool => $r['approved']));
        $this->assertSame('provisional-esi-patient-report-2.0.0+c104bbebce8406d4', $old->version());
    }

    public function test_exclusions_and_minimum_age_keep_handbook_exceptions_out_of_level_two(): void
    {
        $engine = app(ResearchEsiRuleLayer::class);
        [$facts, $concepts] = $this->facts('Chest pain when I cough.', ['chest_discomfort' => 'reported', 'cough' => 'reported']);
        $result = $engine->evaluate($facts, [], $concepts);
        $this->assertNull($result['priority']);
        $this->assertSame('B', $result['stopped_at']);
        $chest = array_values(array_filter($result['trace'], fn (array $r): bool => $r['rule_id'] === 'RP-B-CHEST-ISCHEMIA'))[0];
        $this->assertSame('not_matched', $chest['outcome']);
        $this->assertContains(['concept' => 'cough', 'required_state' => 'not_reported', 'state' => 'reported', 'group' => 'none', 'matches' => false,
            'evidence' => $concepts[1]['evidence']], $chest['observations']);

        $states = ['abdominal_pain' => 'reported', 'severe_pain' => 'reported'];
        [$facts, $concepts] = $this->facts('Severe stomach pain.', $states, 65);
        $this->assertSame(['RP-B-OLDER-ADULT-ABDOMEN', 'RP-B-SEVERE-PAIN-DISTRESS'], $engine->evaluate($facts, [], $concepts)['matched_rule_ids']);
        [$facts, $concepts] = $this->facts('Severe stomach pain.', $states, 64);
        $this->assertSame(['RP-B-SEVERE-PAIN-DISTRESS'], $engine->evaluate($facts, [], $concepts)['matched_rule_ids']);
    }

    public function test_presentations_the_handbook_leaves_to_assessment_remain_needs_review(): void
    {
        $engine = app(ResearchEsiRuleLayer::class);
        foreach ([
            ['I bumped my head on a cabinet.', ['head_injury' => 'reported']],
            ['I am short of breath.', ['breathing_difficulty' => 'reported']],
            ['My knee pain is 9 out of 10 after a fall.', ['limb_injury' => 'reported', 'limb_pain' => 'reported', 'severe_pain' => 'reported']],
            ['There was blood in my stool.', ['bloody_stool' => 'reported']],
            ['Red infected wound and fever; I have diabetes.', ['wound_redness' => 'reported', 'fever' => 'reported', 'diabetes_history' => 'reported']],
            ['I hit my head and feel dizzy.', ['head_injury' => 'reported', 'dizziness' => 'reported']],
            ['Chest pain when I cough, no fever.', ['chest_discomfort' => 'reported', 'cough' => 'reported', 'fever' => 'absent']],
            ['Bruise and a new symptom with no concept.', ['bruising' => 'reported', 'unmapped_symptom' => 'reported']],
        ] as [$text, $states]) {
            [$facts, $concepts] = $this->facts($text, $states);
            $this->assertNull($engine->evaluate($facts, [], $concepts)['priority'], $text);
        }
    }

    public function test_empty_unknown_head_injury_does_not_block_but_quoted_uncertainty_does(): void
    {
        $engine = app(ResearchEsiRuleLayer::class);
        [$facts, $concepts] = $this->facts('Burning and frequent urination, no fever.', ['burning_urination' => 'reported', 'urinary_frequency' => 'reported', 'fever' => 'absent']);
        $unknown = ['concept' => 'head_injury', 'state' => 'unknown', 'evidence' => []];
        $this->assertSame(4, $engine->evaluate($facts, [], [...$concepts, $unknown])['priority']);
        $unknown['evidence'] = $concepts[0]['evidence'];
        $this->assertNull($engine->evaluate($facts, [], [...$concepts, $unknown])['priority']);
    }

    public function test_method_a_prompt_receives_the_engine_checks_verbatim(): void
    {
        $prompt = app(StudyProtocol::class)->prompt();
        $this->assertStringContainsString(json_encode(ResearchEsiRuleCatalog::decisionChecks(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $prompt);
        $this->assertStringContainsString('6. Commit to the answer these steps give.', $prompt);
        $this->assertStringContainsString('A concept the record never mentions is simply missing', $prompt);
        $this->assertStringNotContainsString('explicitly unvalidated', $prompt);
        $this->assertStringNotContainsString('If another symptom is unsupported by all matching profiles, use needs_review.', $prompt);
        $this->assertStringNotContainsString('RP-B-OLDER-ADULT-UTI', $prompt);
    }

    public function test_every_added_rule_cites_a_handbook_edition_and_uses_known_concepts(): void
    {
        $vocabulary = array_keys(NormalizedPatientFacts::definitions());
        foreach (app(ResearchEsiRuleCatalog::class)->rules() as $rule) {
            $this->assertSame([], array_diff($rule['required_concepts'], $vocabulary), $rule['rule_id']);
            if ($rule['handbook_basis'] !== null) {
                $this->assertMatchesRegularExpression('/ESI v[45] Ch\. \d|ESI v[45] Table/', $rule['handbook_section'], $rule['rule_id']);
            }
        }
        $cited = array_filter(app(ResearchEsiRuleCatalog::class)->rules(), fn (array $r): bool => $r['handbook_basis'] !== null);
        $this->assertCount(42, $cited);
    }

    public function test_edition_conflicts_follow_v4_and_engine_conflicts_follow_the_handbook(): void
    {
        $engine = app(ResearchEsiRuleLayer::class);
        $cases = [
            'drooling is v4 level 1' => ['I cannot swallow my spit and I am drooling.', ['unable_to_swallow_saliva' => 'reported'], 1],
            'needle stick without timing' => ['I was stuck by a used needle at work yesterday.', ['used_needle_puncture' => 'reported'], 2],
            'severe ankle pain waits for resources' => ['I twisted my ankle, the pain is severe, but I can walk.', ['limb_injury' => 'reported', 'limb_pain' => 'reported',
                'preserved_limb_function' => 'reported', 'severe_pain' => 'reported'], 4],
            'female severe one-sided lower pain' => ['Severe pain low on my left belly.', ['female_reported_sex' => 'reported', 'left_lower_abdominal_pain' => 'reported',
                'severe_pain' => 'reported'], 2],
            'pregnant generalized cramping is not v4 level 2' => ['I am pregnant with crampy belly pain.', ['pregnancy' => 'reported', 'abdominal_pain' => 'reported'], null],
            'severe pain outside a limb injury answers decision B' => ['Severe pain low on my right belly.', ['right_lower_abdominal_pain' => 'reported', 'severe_pain' => 'reported'], 2],
            'chest pain with cough and fever is not v4 level 2' => ['Cough, fever, chest hurts, tired.', ['chest_discomfort' => 'reported', 'cough' => 'reported',
                'fever' => 'reported', 'general_weakness' => 'reported'], 3],
            'cut on a limb is accounted by the cut profile' => ['Cut on my palm; edges open; bleeding stopped.', ['skin_cut' => 'reported', 'separated_wound_edges' => 'reported',
                'bleeding_stopped' => 'reported', 'limb_injury' => 'reported'], 4],
            'lightheaded on standing is a form of dizziness' => ['Vomiting again and again, lightheaded when I stand.', ['repeated_vomiting' => 'reported',
                'dizziness' => 'reported', 'orthostatic_lightheadedness' => 'reported'], 3],
            'older adult urinary symptoms follow resources' => ['Burning and frequent urination, no fever.', ['burning_urination' => 'reported', 'urinary_frequency' => 'reported',
                'fever' => 'absent'], 4, 75],
        ];
        foreach ($cases as $name => $case) {
            [$text, $states, $priority, $age] = $case + [3 => 40];
            [$facts, $concepts] = $this->facts($text, $states, $age);
            $this->assertSame($priority, $engine->evaluate($facts, [], $concepts)['priority'], $name);
        }
    }

    /** @param array<string, string> $states @return array{array, array} */
    private function facts(string $text, array $states, int $age = 40): array
    {
        $facts = [['field' => 'age', 'state' => 'reported', 'value' => (string) $age, 'evidence' => (string) $age],
            ['field' => 'symptom_description', 'state' => 'reported', 'value' => $text, 'evidence' => $text]];
        $concepts = [];
        foreach ($states as $concept => $state) {
            $concepts[] = ['concept' => $concept, 'state' => $state, 'evidence' => [['field' => 'symptom_description', 'quote' => $text]]];
        }

        return [$facts, $concepts];
    }
}
