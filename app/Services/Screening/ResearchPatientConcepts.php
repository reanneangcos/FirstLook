<?php

namespace App\Services\Screening;

final class ResearchPatientConcepts
{
    public const VERSION = 'patient-concepts-v2-research';

    /** Factual meanings only: no ESI level, diagnosis, risk judgment or resource estimate. */
    public static function definitions(): array
    {
        return [
            'unresponsive_now' => 'Proxy explicitly says the person currently cannot be woken, does not answer, or does not respond. Not ordinary sleep or fatigue.',
            'gasping_or_noisy_breathing' => 'Explicit current gasping, gurgling, or abnormal noisy breathing. Ordinary snoring without a current complaint is insufficient.',
            'material_in_mouth' => 'Explicit vomit, blood or pooled saliva currently in the mouth of the described person.',
            'blue_colour' => 'Proxy reports blue lips, face or area around the mouth; not a measured oxygen value.',
            'active_convulsions' => 'Ongoing whole-body shaking described as a current seizure-like event, or repeated episodes without waking between them. Not a diagnosis.',
            'recent_seizure' => 'Explicit report of a recent seizure event; a remote seizure history is insufficient.',
            'pallor' => 'Patient/proxy reports becoming pale with this event; not an examined finding.',
            'dizziness' => 'Reported dizziness, lightheadedness or a spinning sensation.',
            'back_pain' => 'Reported back pain; not automatically flank pain.',
            'tearing_pain' => 'Pain explicitly described as tearing or ripping.',
            'new_motor_weakness' => 'Explicit newly developed loss of strength in a limb or limbs; not general fatigue.',
            'bilateral_leg_weakness' => 'Reported weakness in both legs.',
            'saddle_numbness' => 'Reported reduced sensation between the legs or around the groin.',
            'difficulty_urinating' => 'Reported difficulty passing urine or urinary retention; not burning alone.',
            'allergen_exposure' => 'Reported exposure associated with a current allergic-type complaint; not inferred from the symptom alone.',
            'lip_or_tongue_swelling' => 'Reported swelling of the lips or tongue.',
            'swallowing_difficulty' => 'Reported new difficulty swallowing.',
            'eye_pain' => 'Reported pain in the eye.',
            'vision_change' => 'Reported newly blurred, lost, or altered vision, including halos.',
            'cough' => 'Reported cough.',
            'coughing_blood' => 'Reported blood coughed up.',
            'vomiting_blood' => 'Reported blood in vomit.',
            'bloody_stool' => 'Reported blood in stool or black tar-like stool.',
            'general_weakness' => 'Reported fatigue or generalized weakness, distinct from focal motor weakness.',
            'repeated_vomiting' => 'Explicit repeated vomiting or inability to keep intake down; one nausea episode is insufficient.',
            'poor_oral_intake' => 'Reported inability or marked difficulty keeping food or fluids down.',
            'diarrhea' => 'Reported loose or watery stools.',
            'orthostatic_lightheadedness' => 'Reported lightheadedness specifically on standing; not measured orthostatic hypotension.',
            'urinary_urgency' => 'Reported urgent need to urinate.',
            'limb_injury' => 'Reported recent injury to an arm, leg or joint; do not infer fracture.',
            'limb_pain' => 'Reported pain in an arm, leg or joint.',
            'limb_swelling' => 'Reported swelling of an injured limb or joint.',
            'preserved_limb_function' => 'Explicit retained ability to walk, move, or feel the injured limb; not a neurovascular examination.',
            'skin_cut' => 'Reported cut or laceration of the skin.',
            'bleeding_stopped' => 'Explicit report that the current wound bleeding has stopped or is controlled.',
            'ear_foreign_body' => 'Explicit account of an object remaining lodged in the ear; not a suspected diagnosis.',
            'localized_itchy_skin' => 'Explicit limited itchy skin patch or a few localized itchy bites; not a generalized eruption.',
            'generalized_rash' => 'Explicit widespread rash or hives; not a few localized spots.',
            'feels_well' => 'Explicit statement of otherwise feeling normal or well. Does not independently establish every symptom as absent.',
            'no_acute_symptoms' => 'Explicit scoped statement of no current symptoms; not a missing or unreported symptom field.',
            'runny_nose' => 'Reported runny nose.',
            'sneezing' => 'Reported sneezing.',
            'scratchy_throat' => 'Reported scratchy or irritated throat.',
            'watery_eyes' => 'Reported watery eyes.',
            'usual_activities_preserved' => 'Explicitly able to carry out usual daily activities.',
            'eating_drinking_normally' => 'Explicitly able to eat or drink normally.',
            'symptoms_improving' => 'Explicit improvement of the current complaint; stable or unchanged is not improvement.',
            'head_injury' => 'Reported recent blow or injury to the head.',
            'anticoagulant_use' => 'Explicit reported use of an anticoagulant; do not infer from a condition.',
            'chills' => 'Reported chills or shivering with the current complaint.',
            'numbness' => 'Reported loss or reduction of sensation.',
            'tenderness' => 'Patient reports soreness or tenderness, not an examination finding.',
        ];
    }
}
