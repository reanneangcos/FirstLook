# Current prototype mode — 21 October 2026

The active default is now `research_prototype`, with 70 deterministic patient-report profiles (version 2.0.0; 38 added from ESI v4 and v5 handbook categories). Prototype execution does not wait for clinical approval. The existing 18 reviewed definitions and their approval hashes remain unchanged in `provisional_patient_report` mode.

See [the current implementation, sources, and complete flowchart](research-prototype-rules.md). The sections below document the earlier reviewed-mode implementation and its historical constraints. Do not apply those approval gates or mandatory negative bundles to the new prototype mode.

---

# Provisional rule layer and next steps

## Current implementation — October 20, 2026

The clinical catalog now identifies the **2012 Edition, AHRQ Publication 12-0014 (November 2011)** cited in the thesis. Its version is `esi-v4-adult-1.3.0`. Source references now use the corresponding chapters and printed pages. The earlier supplied `Esi_Handbook.pdf` was the May 2005 edition; that file and previously saved results have not been changed. No hash from the 2005 file is presented as a hash of the 2012 source.

`config/esi.php` selects `provisional_patient_report`. Luna returns verbatim `extracted_facts` plus language-independent `normalized_facts`. The patient-report engine checks concept identifiers and states in A/B/C order. It does not compare patient sentences against English templates or ask Luna for clinical risk judgments, resource counts or Method B priorities. The separate handbook/clinical engines still require explicit clinical assessments.

**Recorded review:** the project user reported verbal approval by **Abigail Rose Gayatin-Sabandal, PHRN, USRN**, dated **2026-10-20**, for the current rule layer. `config/esi.php` records this against the 18 current patient-report definitions under internal record ID `FIRSTLOOK-REPORTED-VERBAL-2026-10-20-01`. The evidence is the user's report in the project chat; no direct reviewer statement or signed document was supplied. No revisions or exceptions were supplied. The record's scope follows the user's statement about the current layer.

The approval version and definition hashes are fixed: changed definitions or concept meanings invalidate approval, and new rules do not inherit it. These records enable matching current rules in Method B; they do not establish measured clinical accuracy, complete condition coverage, or multilingual extraction accuracy. Development pre-testing and the study's dataset-release and pilot records still precede the final frozen experiment.

Read-only checks:

```bash
docker compose exec -T app php artisan study:check --no-interaction
docker compose exec -T app php artisan study:check --review --no-interaction
docker compose exec -T app php artisan esi:patient-preview --no-interaction
docker compose exec -T app php artisan esi:patient-preview --markdown --no-interaction
docker compose exec -T app php artisan esi:preview --json --no-interaction
```

`study:check --review` exports the concrete handbook decisions, their exclusions, candidate input fields, and the exact remaining mapping decisions for the nurse. It includes any configured patient rules and their approval status. It does not create approvals. A failed readiness check exits with code 1 intentionally.

`esi:preview` demonstrates fictional clinical inputs. Outputs remain `draft_preview`, with `priority=null`, a separate `candidate_priority`, and `study_eligible=false`. They are not clinical approvals or study results.

### Handbook decisions and provisional ESI 3

| Decision | Explicit established condition | Proposed action |
| --- | --- | --- |
| A | Immediate lifesaving intervention required | ESI 1. Explicit negative permits B. |
| B: high risk | A negative; a high-risk situation established in context | ESI 2. Handbook examples include active chest pain suspicious for ACS, stroke signs, healthcare-worker needle stick, fever during chemotherapy, and suicidal/homicidal presentation. These are not unrestricted keyword triggers. |
| B: mental status | A negative; acute confusion, lethargy or disorientation, distinct from baseline | ESI 2. Ordinary tiredness and chronic baseline confusion do not establish this condition. |
| B: pain/distress | A negative; assessment establishes qualifying severe pain or distress | ESI 2. A score of 7/10 or higher alone is insufficient. |
| C | A and every B criterion negative; supported estimate of expected resource types available | 0 -> ESI 5; 1 -> ESI 4; 2 or more -> provisional D branch. |
| D: study adaptation | A/B explicitly negative; at least 2 expected resources established; no vital signs in study inputs | Numeric prediction 3; label below; D unassessed. |
| Any required decision | Required information missing, unknown, not applicable or contradictory; or approval pending | Needs Review with null priority. No default ESI 3. |

The provisional display is **“Most likely ESI 3 - nurse evaluation and vital signs required.”** It is a numerical prediction with `status=classified`, `priority=3`, `output_kind=provisional_esi_prediction`, `clinical_review_required=true` and `decision_d.status=not_assessed`. “Most likely” is requested display wording, not a calibrated probability. It does not establish that waiting is safe. In a patient-only study, every predicted 3 remains provisional. Unclear A/B/C facts produce Needs Review, not a likely 3.

The handbook source for A/B/C is Chapter 2, printed pp. 8-14; resource definitions are in Chapter 4, pp. 29-31. Deferring D is a **study adaptation**, informed by Chapter 2, p. 14 and Chapter 5, pp. 35-37, and needs its own review. In the separate clinical mode, HR >100/min, RR >20/min or oxygen saturation <92% prompts consideration of ESI 2 with clinical judgment; it does not automatically assign ESI 2.

**Input boundary for the nurse:** the chatbot currently extracts verbatim excerpts from 15 patient fields. Those excerpts do not themselves establish `immediate_lifesaving_intervention_required`, the three B assessments, or `expected_esi_resources`. The clinical engine accepts documented assessments; preview vignettes supply them explicitly to test the rules. Such assessments cannot silently be added to the patient-only experiment. The patient-report mappings specify the proposed positive/negative conditions and resource rationale. Their recorded review provenance is described above; software checks cannot verify their medical adequacy. Existing validated case labels remain unchanged.

Tests already performed/requested and devices in place remain context. They do not automatically become expected-resource counts. CBC plus urinalysis is one laboratory resource; CBC plus X-ray is two types. History/examination, point-of-care tests, oral medication, prescription refills, tetanus immunization and simple wound care are not counted resources (Table 4-1). An incomplete workup list cannot establish zero or exactly one resource.

### Final-testing treatment of Needs Review

Follow Section 3.13 of the manuscript, with prediction status and clinical-review flags recorded separately:

| Output | Classification scoring | Additional reporting |
| --- | --- | --- |
| Accepted provisional ESI 1-5, including provisional 3 | Score its numerical level against the reference. A predicted 3 is correct only when the reference is 3. | Report provisional-3 counts and errors separately. Pending bedside assessment does not itself make this an abstention. |
| Needs Review (`priority=null`) | No sixth level, imputed 3, or over/undertriage classification. | Count as an abstention; keep it in attempted-input denominators. |
| Technical failure / no accepted output | No ESI level. | Count separately from Needs Review; retain in attempted-input denominators. |
| Draft preview / unapproved-rule demonstration | Exclude from final study runs. | Software and nurse review only; candidate levels are not study predictions. |

For each of the three runs:

1. Compute conditional macro-F1, accuracy, confusion matrices and over/undertriage on the **same common valid paired set**, where both A and B produced numerical levels. Report that set's size.
2. Report each method's **coverage = numerical predictions / attempted inputs** and the common-pair coverage.
3. Report **Needs Review rate = abstentions / attempted inputs**, overall and by reference level, language and abstention reason. Report technical failures separately.
4. Report **completion-adjusted accuracy = correct numerical predictions / attempted inputs**. Abstentions contribute no correct prediction and remain in the denominator.
5. Apply the paper's requirement for valid identical numeric outputs to consistency and repeatability. A group containing Needs Review does not count as successfully consistent. Agreement between abstention statuses can be described separately.
6. Report all three runs and their arithmetic mean. Preserve canonical-case grouping: 600 versions x 3 runs gives 1,800 paired records representing 120 underlying cases.

Illustration only: if A predicts all 100 inputs and B predicts 80, with 20 Needs Review outcomes and 60 correct predictions, B's conditional accuracy on the common paired set is 60/80 = 75%; coverage is 80%; Needs Review rate is 20%; completion-adjusted accuracy is 60/100 = 60%. If both methods abstain on different inputs, compute their intersection explicitly. These figures are not study results.

If no valid pairs exist, conditional comparison is **not estimable**. Report coverage and abstention reasons. Do not treat an unconfigured engine as a completed hybrid experiment, remove difficult cases, retry an accepted abstention to obtain a preferred answer, or overwrite the frozen pre-screening prediction with a later nurse result.

## Current status

The selectable `provisional_patient_report` mode uses `ProvisionalEsiRuleLayer`. After mappings are reviewed and configured, it can operate on patient reports without measured vital signs. It records provisional predictions with `decision_d.status = not_assessed`. It is the active default; unapproved rules still cannot classify.

**The 18 current patient-report mappings have recorded approval based on the user's report of verbal review.** `config/esi.php` obtains their definitions from `DraftPatientMappings` and stores approval records separately. The original `DRAFT-*` identifiers and proposal text are retained so recording approval does not rewrite the reviewed conditions. They identify proposal history, not a claim of performance validation. The existing validated case labels have not been changed or used to generate these proposals. Current patient submissions can classify when the required evidence matches an approved definition. Missing facts and unmatched presentations still produce Needs Review. Software fixtures and preview candidates are never final-study results.

The existing `EsiV4RuleLayer` remains available under `esi.mode = clinical_esi_v4`. Its A–D assessments and measured-vitals requirement are unchanged. The selectable `provisional_patient_report` method is a separate study adaptation and cannot be described as complete clinical ESI v4.

## Source-linked patient-mapping drafts

`DraftPatientMappings` contains 18 explicit proposals derived from named handbook examples. They are **adaptations**, not rules whose clinical validation transfers from the handbook. The downloaded 2012 government-source PDF has SHA-256 `0a3c86679e461b7410f8df534ee7cf59c7424dc4e522dbeb5fcb0f0cce62ed09`.

| Decision / draft candidate | Included profiles | Source in the 2012 handbook |
| --- | --- | --- |
| A / 1 | Current proxy report of absent breathing and responsiveness | Chapter 2, pp. 8–11 |
| B / 2 | Concerning chest-symptom cluster; sudden weakness and speech change; first sudden worst headache; known allergy with throat tightening; reported fever during chemotherapy; recent used-needle exposure; excessive medicine ingestion; new confusion; current suicidal intent; severe pain with cancer history | Chapter 2, p. 12; Chapter 3, pp. 18–22 and 26–27 |
| C / provisional 3 | Right lower abdominal symptoms with nausea/reduced appetite; unilateral lower-leg symptoms after travel | Table 4-2, p. 33; D remains unassessed |
| C / 4 | Isolated ankle-injury profile; narrow urinary-symptom profile; isolated finger-cut profile | Tables 4-1/4-2, pp. 30–33; Chapter 9, cases 13/21 |
| C / 5 | Routine blood-pressure prescription refill; simple dressing recheck | Tables 4-1/4-2, pp. 30 and 33 |

The catalogue records positive concept conditions, proposed earlier-decision exclusions, resource rationale, source location and the clinical information omitted from the source examples. It does not infer a diagnosis, normal vital signs or an actual resource count. C profiles encode a proposed resource-related expectation, which is separately identified for review; a requested/completed test or existing device cannot supply it.

The shared A/B exclusion concepts have the reported review provenance above; they have no measured validation findings from the final study yet. They require explicit positive or negative reports; a generic denial cannot be expanded into individual denials. The C profiles include explicit negatives for the listed B presentations so a missing rule match cannot clear B. A broad denial of symptoms does not establish absence of every high-risk condition. Review applies to the specific mapping's exclusions and context; approval of the handbook's decision-order diagram alone does not approve these adaptations.

Luna interprets English, Tagalog, Bisaya, code-switching, synonyms and clear typos into one concept vocabulary. For example, a reported headache uses `headache` regardless of the source language. Each concept has a state and verbatim evidence anchors into accepted source excerpts. The rule layer consumes those concepts without maintaining translation or typo dictionaries. Ambiguous interpretations stay unknown; incompatible reports stay contradictory. Original-language evidence is never translated or overwritten.

The new output contract is `screening-v2-normalized`, with validator `evidence-v4-reporting-states` and vocabulary `patient-concepts-v1`. The exploratory prompt is `screening-v0.8-reporting-states`; its Method A decision instructions remain the baseline instructions. The added extraction requirements can affect future model behavior, so this is a new version, not evidence that old and new predictions are interchangeable. Historical prompts, records and results are not rewritten. Intake uses `intake-v6-reporting-states`. The active intake, exploratory and study prompts share the same whole-field unknown/absence convention. A labeled placeholder inside a longer statement does not force the complete field to absent or unknown. The admin display preserves the original text. Retry feedback `validation-feedback-v2-reporting-states` explains a rejected absence state without supplying a priority.

`NormalizedPatientFacts` constrains concept names, states and evidence provenance. It excludes decision labels, clinical high-risk judgments, diagnoses, measured vitals and resource estimates from the vocabulary. Tests/devices cannot provide normalized symptom evidence. These checks do not prove semantic correctness: mocked multilingual tests establish the software contract, not Luna's measured extraction accuracy. Unsupported reported symptoms are retained as `unmapped_symptom` and block lower-priority resource classification. The 18 profiles are still not exhaustive clinical coverage.

### Preview and review report

`esi:patient-preview` produces 18 deliberately constructed matching examples plus six unresolved examples: missing A/B exclusions, pain score alone, tests/devices alone, an unlisted presentation, contradictory concepts and a non-adult input. These are software demonstrations, not DEV results, validation findings or evidence of coverage. Every result keeps `priority=null`, `status=draft_preview` and `study_eligible=false`; a proposed level appears only in `candidate_priority`. Normal evaluation continues to require approval of the exact definition hash.

Use `--markdown` for a readable report containing each rule's conditions, current approval provenance, and fields for subsequent review. Use `--json` for the full audit traces, or `--input=/path/to/fictional-facts.json` for a JSON object containing `extracted_facts` (up to 15 fields) and `normalized_facts` (concepts and evidence). No option performs an LLM call, creates a nurse approval, or writes to the patient/study database.

The review exports are under `storage/app/private/studies/review/`. The `patient-mapping-drafts.md` report is intended for the nurse; `patient-mapping-drafts.json` contains the machine-readable definitions and traces.

### Coverage limits

The handbook itself calls its high-risk examples non-exhaustive (Chapter 3, pp. 17 and 27). There is no claim of coverage of every symptom, combination, age group, medication or clinical condition. Unmatched or unresolved input returns Needs Review, with an explicit reason. Supporting a deterministic response for every input is different from supporting a numerical ESI prediction for every condition. No source establishes that this patient-text adaptation is clinically validated.

## Evidence supporting the design

- [Gaber et al. 2025](https://doi.org/10.1038/s41746-025-01684-1): compared symptom/history inputs with inputs adding vital signs; supports the limited-information prediction task.
- [Sharma et al. 2026](https://pubmed.ncbi.nlm.nih.gov/42612553/): distinguishes provisional prediction before measurements from recommendations afterward. The published abstract and the public implementation described below were inspected; the full journal article was not available for inspection.
- [Dehghani Soufi et al. 2018](https://doi.org/10.1016/j.ijmedinf.2018.03.008): supplies an actual rule/fuzzy-system example. Its published rule table uses clinical assessments, resources and measured vital signs.
- [Elias et al. 2015](https://pmc.ncbi.nlm.nih.gov/articles/PMC4526930/): supports vignette comparison against ESI labels; included vital signs and examination findings when available.
- [ESI v4 handbook](https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf): clinical decision structure and resource definitions. Chapter 5, printed page 36, requires vital-sign assessment for completed ESI 3 triage.

The articles support research scope and evaluation. They do not supply approved symptom-to-ESI conditions for this project. The engine records them as `design_sources`, separate from each rule's handbook source and adaptation rationale.

### What the published implementations actually require

Source inspection on October 11, 2026 found the following:

| Source inspected | Decision mechanism | Consequence for this project |
| --- | --- | --- |
| Soufi, Sections 2.1–2.2 and Table 4, printed pp. 38–39 ([full-text copy](https://iranarze.ir/storage/uploads/2018/04/E6694-IranArze.pdf)) | Rule inputs include assessed consciousness, intervention need, high risk and resources. A fuzzy component uses measured vital signs to distinguish levels 2 and 3. | Supports separating a rule base from its execution engine. The table cannot supply missing clinical inputs or a symptom-only ESI 3 mapping. |
| Gaber, Supplementary Figure 1 and Table 1 ([supplement](https://media.springernature.com/original/springer-static/esm/art%3A10.1038%2Fs41746-025-01684-1/MediaObjects/41746_2025_1684_MOESM1_ESM.pdf)) | The general-user prompt asks the LLM to choose a triage level from history and personal information. | Useful for the prediction experiment and Method A. It is not a deterministic patient-fact rule table. |
| Sharma, public implementation: [PAA agent](https://github.com/Karthick47v2/ED-Triage-Agent/blob/main/ed_triage/paa/agent.py), [prompt](https://github.com/Karthick47v2/ED-Triage-Agent/blob/main/ed_triage/paa/prompts.py), [resource rubric](https://github.com/Karthick47v2/ED-Triage-Agent/blob/main/ed_triage/common/esi_resource_rubric.py) | `run_paa` calls a structured-output LLM. Its prompt asks that LLM to infer likely resources and produce `tentative_esi`. | Supports a provisional phase before measurements. Copying its decision method would give the LLM a deciding role in Method B and conflict with the project's ban on inferred resource counts. The current repository is supporting implementation evidence; equivalence to the exact publication experiment was not established. |
| Elias, Methods: “Clinical GPS and Emergency Severity Index Algorithm Differences” ([article](https://pmc.ncbi.nlm.nih.gov/articles/PMC4526930/)) | cGPS derives a score from a weighted differential diagnosis; the vignette inputs include available measurements and examination findings. | Supports comparison with ESI reference labels. Its diagnosis-based decision method does not match this project's boundaries. |

These findings do not establish a complete, transferable set of deterministic rules for the 15 allowed patient fields. Published performance belongs to the evaluated system and inputs; it does not validate a different rule set.

### Clinical predicates that can be specified from the handbook

The following is the clinical specification already represented by `EsiV4RuleCatalog`, not a newly activated patient-report mapping. B and C require explicit negative findings at all earlier decision points. Sources are the [handbook](https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf), Chapter 2, printed pp. 8–14; Chapters 3–5 provide further detail.

| Existing rule ID | Required established condition | Supported clinical action |
| --- | --- | --- |
| `ESI4-A` | Immediate lifesaving intervention needed | ESI 1 |
| `ESI4-B-HIGH-RISK` | High-risk situation | ESI 2 |
| `ESI4-B-MENTAL-STATUS` | Acute confusion, lethargy or disorientation; distinguish baseline | ESI 2 |
| `ESI4-B-PAIN-DISTRESS` | Clinical assessment supports severe pain/distress; score alone insufficient | ESI 2 |
| `ESI4-C` | Zero expected ESI resources | ESI 5 |
| `ESI4-C` | One expected ESI resource | ESI 4 |
| `ESI4-C` | At least two expected ESI resources | Proceed to D |
| `ESI4-D` | Adult measured HR >100, RR >20 or oxygen saturation <92% | Consider ESI 2 with clinical judgment |
| `ESI4-D` | Required measurements available outside those danger zones | ESI 3 |

The app's patient fields contain narrative symptoms, history and test/device context. They do not contain these established clinical assessments or an expected-resource estimate. Being able to type into a chatbot cannot establish a negative A decision, and a failed rule match cannot establish a negative B decision. Missing clinical inputs remain unresolved.

### The exact remaining specification for provisional ESI 3

The reviewed patient-report adaptation would need all of the following:

1. A general combination of permitted facts that positively supports the prediction. “No rule matched” is not such a condition.
2. Explicit patient-report conditions that support the study's exclusions at A and B, with their limitations documented.
3. A source-linked rationale for the C mapping. Prior/requested tests and an existing catheter cannot silently become expected resources.
4. A recorded decision that the output is a **provisional ESI-scale prediction**, with D unassessed and clinical assessment pending.
5. Defined missing/conflicting-fact handling and the review record for that exact definition.

No inspected paper supplies all five items for this dataset. The reviewer must assess the proposed interpretation; merely signing an incomplete row does not supply its missing conditions. If the permitted facts cannot support a mapping, retain Needs Review and report its effect on coverage. The existing dataset labels remain unchanged.

The executable handbook preview specifies clinical decisions and the provisional D branch. It does not establish that a particular patient-reported phrase supplies a clinical assessment. The review packet makes that remaining distinction explicit.

## What the code checks

1. Facts must come from the accepted extraction and use the existing patient-field allowlist. The current rule scope requires an explicit adult age. Duplicate fields cannot classify.
2. Rules run in A, B, C order. A maps to 1; B to 2; C to 3, 4 or 5. The first concept in a profile identifies its presentation. A reported presentation with missing context blocks later decisions. An unmentioned presentation stays unresolved and is never treated as a negative assessment; later rules must independently satisfy their explicit A/B exclusions.
3. A B mapping requires explicit exclusion conditions for A. A C mapping requires exclusion conditions for both A and B. A failed match is never an exclusion.
4. Current conditions check a concept identifier and required state. Missing, unknown, not-applicable, contradictory and explicitly absent remain distinct. Evidence anchors preserve original text. Legacy field-condition support remains for existing configurations and software fixtures; the current 18 definitions contain only concept conditions.
5. A C mapping needs a documented resource-related rationale. Tests and devices never become resource counts automatically. The software does not judge whether a clinician's rationale is medically adequate; that is the rule review's purpose.
6. Applicable conflicting priorities or unresolved required facts return Needs Review. Optional history does not automatically block a supported rule.
7. Approval must identify the reviewer, date, record, engine version and exact definition hash. Editing a rule or the concept vocabulary invalidates old approvals. Definitions, concept meanings and approval records affect the persisted rule version.
8. Both methods share the accepted understanding from one LLM response. `method_b_input` stores verbatim and normalized facts plus an empty clinical-assessment object. Method B never receives Method A's label or explanation and never calls the LLM. Existing saved screenings remain unchanged.

Evaluate whether different wording yields equivalent concepts using development data only. Do not tune prompts, concept definitions or rules against held-out test wording or labels. Clinical review concerns the meaning and adequacy of the decision conditions; it does not require enumerating every possible translation or typo.

## Rule specification to complete with the reviewer

Prepare one row or record per general mapping. This is a review of the decision procedure, not a request to revalidate all case labels.

| Required item | What to record |
| --- | --- |
| Rule ID | Stable identifier independent of a dataset case ID |
| Decision point and output | A/1, B/2, or C/3–5, always provisional |
| Supporting conditions | Standardized patient concept, explicit required state and evidence provenance |
| Earlier-decision exclusions | Explicit conditions for A when returning 2; for A and B when returning 3–5 |
| Source | Handbook chapter and printed page, with the relevant clinical concept |
| Adaptation rationale | Why the permitted patient-reported facts support this provisional prediction and what remains unassessed |
| Resource rationale | For C, why the mapping is appropriate without pretending to observe a future resource count |
| Explanation | Brief non-diagnostic explanation suitable for the saved result |
| Review record | Reviewer, date, approved/revise/reject, retained record ID, exact reviewed definition |

Do not make a rule per final-test row or use an answer key, case ID, reviewer rationale, model label, measured vital or predicted diagnosis as a condition.

### Configuration contract

Each entry in `esi.provisional_rules` is keyed by rule ID and contains exactly:

```text
decision_point: A, B or C
priority: integer 1–5 consistent with the decision point
conditions: ordered nonempty list of {concept, state}; first concept identifies the presentation
exclusions: {} for A; {A: conditions} for B; {A: conditions, B: conditions} for C
handbook_section: source chapter and printed page
adaptation_rationale: documented reason for the study adaptation
resource_rationale: required text for C; null for A/B
explanation: brief non-diagnostic result explanation
```

Conditions in a list are joined with AND. Concept identifiers come from `NormalizedPatientFacts`; they describe reports, not clinical decisions. Legacy `{field, state, values}` conditions remain supported for older configurations. Accepted condition states are `reported`, `absent`, and `not_applicable`; uncertainty cannot satisfy a condition. Patient data cannot set approval or configuration.

Each corresponding entry in `esi.provisional_approvals` records `status`, `reviewer`, `reviewed_at` (YYYY-MM-DD), `record_id`, `rule_version`, and `definition_hash`. The engine version is `ProvisionalEsiRuleCatalog::VERSION`; the hash is calculated with `ProvisionalEsiRuleCatalog::definitionHash($definition)` when recording the review, then saved as a literal. Never recalculate an approval's hash automatically on application startup. This hash links an approval to exact content; it is not proof of clinical review. Preserve whether the underlying evidence is written, direct verbal confirmation, or a user's report of verbal approval. The current records explicitly use `approval_method=verbal`, `evidence_type=user_report`, `direct_reviewer_confirmation=false`, and `signed_record_reference=null`.

## Next steps in order

1. **Protect the existing reference dataset.** Preserve its labels. Have the custodian establish the paper's canonical-case development/test split before prompt or rule tuning. Do not expose final-test labels for rule construction.
2. **Keep the review record linked to the rules.** The current 18 mappings have a recorded user report of verbal approval. Record any requested revisions or rejected mappings; changed rules require a new review record tied to their new hashes. Do not present this record as a signed document or as original dataset validation.
3. **Enter only approved mappings and pre-test development cases.** Start with the paper's 10 pilot cases in the development set. Examine matched rules, missing facts, no-match cases, unsupported phrasing and conflicts. Software tests may be run now with `php artisan test --compact tests/Unit/ProvisionalEsiRuleLayerTest.php tests/Feature/EsiRuleIntegrationTest.php tests/Unit/EsiV4RuleLayerTest.php`. They verify implementation, not clinical accuracy.
4. **Freeze the comparison.** The final runner uses `screening-study-v3`, which inserts the same reviewed definitions used by B into A's prompt. A still decides independently; B receives only A's accepted extracted facts. Saved A outputs remain unchanged. New chatbot/DEV outputs use the versioned normalized-extraction prompt. The freeze records the exact criteria, approval records, prompt/schema/code hashes, model settings, dataset hash and separate reference-key hash. Any subsequent configuration or code change prevents resuming that experiment.
5. **Run and score the frozen comparison.** The commands below now implement three fresh repetitions, saved separately. Accepted Needs Review outputs are not retried. Technical/schema failures use the existing maximum of three attempts; interrupted records stop for inspection. Resuming skips all saved terminal outcomes, including failures, and fills only missing versions. Each record has a separate patient stub and structured-submission transcript in the admin dashboard. It is not represented as a multi-turn patient conversation.

## Final evaluation commands

These are future execution steps, **not commands that were run on your real TEST data during this change**. First complete review and the development pilot record. Select `provisional_patient_report` only with the approved patient mappings in place.

The input JSON uses the same structure as the original DEV manifest, with `split: TEST`, `source_sha256`, and `cases`. Each case has `split`, `dataset_case_id`, `variant_id`, `language`, and `patient` containing exactly the 15 allowed fields. No labels, rationale columns or other metadata are allowed in `patient` or case entries. It must contain 120 canonical cases, each with English, Tagalog, Bisaya and two distinct code-switch variants, for 600 versions. Code-switch pairs may differ across canonical cases.

Keep references in a separate JSON list containing only `{variant_id, expert_priority}` for all 600 versions. Every version of a canonical case must share its validated reference. The freeze validates the key and stores its hash, without including the labels in patient prompts. Scoring joins the key only after all 1,800 records are present.

```bash
# Validate the proposed freeze without making requests or writing a freeze.
# Replace the paths and record IDs with actual retained records.
# Choose the English-only secondary test repetition BEFORE seeing results.
docker compose exec -T app php artisan study:freeze \
  storage/app/private/studies/test-input.json \
  --study-id=final-v1 \
  --reference=storage/app/private/studies/test-reference.json \
  --dataset-review-record=ACTUAL_DATASET_RELEASE_RECORD \
  --pilot-record=ACTUAL_PILOT_RECORD \
  --secondary-run=1 --seed=42 --dry-run --no-interaction

# Once valid, repeat the freeze command without --dry-run.
# These three commands incur API usage; execute only when final testing is authorized.
docker compose exec -T app php artisan study:run final-v1 --run=1 --no-interaction
docker compose exec -T app php artisan study:run final-v1 --run=2 --no-interaction
docker compose exec -T app php artisan study:run final-v1 --run=3 --no-interaction

# Offline scoring; no API calls.
docker compose exec -T app php artisan study:score final-v1 \
  storage/app/private/studies/test-reference.json --no-interaction
```

`study:run ... --dry-run` verifies the frozen configuration without requests or writes. `--limit` can pause a repetition after a specified number of new records; rerun with the same ID to continue. Do not create a second study ID to replace unfavourable predictions. Existing freezes and score reports cannot be overwritten; alternative report names must be supplied explicitly.

### Output and interpretation

Reports are saved under `storage/app/private/studies/<study-id>/`: `freeze.json`, `results-v1.json` and `results-v1.csv`. The CSV preserves the run, canonical case, variant, language, reference, both statuses/priorities, database IDs and failure information. Saved reference labels and predictions are never changed by scoring.

The JSON reports each run and the three-run arithmetic mean: common-pair accuracy, five-class macro-F1, misclassification, overtriage, undertriage, coverage, abstention, technical failure and completion-adjusted accuracy. It also provides confusion matrices, reference-level and language breakdowns, five-variant consistency and three-run repeatability. A zero-support class contributes zero to macro-F1; an empty common-pair set yields null, not a fabricated score.

The primary uncertainty calculation follows Section 3.13: 2,000 paired bootstrap resamples of canonical groups, seed 42, with all five variants and all three runs kept together. It recalculates the mean per-run B-minus-A difference and reports linear-interpolated 2.5th/97.5th percentiles plus undefined-resample counts. PHP's local `Randomizer/Mt19937` is recorded for reproducibility.

The secondary McNemar analysis uses English versions from the repetition explicitly selected at freeze time, on common valid pairs. The paper does not select a repetition or quantify “small” discordant counts; the implementation makes these choices explicit before testing: use exact two-sided binomial calculation when discordant pairs are fewer than 25, otherwise the continuity-corrected chi-square approximation. Confirm these implementation choices with the research adviser before freezing. This does not select the best repetition after seeing results. [Statsmodels documents the exact and corrected alternatives](https://www.statsmodels.org/stable/generated/statsmodels.stats.contingency_tables.mcnemar.html).

B is reported as an upstream failure when no accepted A facts are available. That is separate from a rule-engine exception. Both remain in the completion denominator. Retries are saved attempts, not additional cases. Multiple returned model identifiers are flagged for inspection.

The runner and scorer do not establish clinical approval, patient-rule coverage, API access, or actual performance. External benchmarking from the paper (Docker CPU utilization, peak host memory and consistent host/network conditions) still requires a planned measurement session. Historical DEV findings are not final TEST findings.
