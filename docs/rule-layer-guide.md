# Provisional rule layer and next steps

## October 12: supplied May 2005 handbook

`config/esi.php` now selects `handbook_pre_screening`. The source is the supplied `Esi_Handbook.pdf`, **ESI v4, May 2005, AHRQ 05-0046-2**. Each result and rule trace identifies that edition and the file's SHA-256. This is a different edition from the 2012 handbook discussed in the October 11 history below. The current engine version is `esi-v4-adult-1.2.0`.

- A follows Chapter 3, pp. 16–19. An explicitly assessed immediate intervention can be supplied as a boolean or a list of the Table 3-1 intervention tokens. Oxygen, monitoring and IV access alone neither establish nor exclude A. A contradictory negative assessment blocks classification.
- B preserves the three assessed criteria and pain-score limitation in Chapters 3–4. A must be explicitly excluded first. The engine does not infer these clinical judgments from narrative keywords.
- C accepts an explicit expected-resource count or a verified `expected_resource_plan` containing `complete` (boolean) and `items` (service tokens). `EsiV4ResourceCounter` groups blood/urine labs, avoids counting repeated X-rays, and excludes the Table 5-1 non-resources. A complex procedure counts as two. An incomplete plan can support the two-or-more branch only when two resources are already established; it cannot establish zero or exactly one. Contradictory count/plan inputs return Needs Review. The supported token list is included in `esi:preview --json`.
- The separately reviewed provisional D branch still returns **“Most likely ESI 3 - nurse evaluation and vital signs required”** only after supported negative A/B and at least two expected resources. Chapter 6, pp. 41–43 requires the omitted measurements for a completed clinical ESI 3 assessment. D remains `not_assessed` in this study adaptation.

Run `docker compose exec -T app php artisan esi:preview --no-interaction` for 18 fictional demonstrations, or add `--json` for full specifications and traces. These remain draft candidates, not study results. Method A and previously saved results remain unchanged.

**Current patient-workflow limitation:** no approvals or patient-response mappings have been supplied. The chat passes its unchanged extracted facts and no clinical assessment; the handbook engine therefore returns Needs Review at A when adult eligibility is known. Selecting this engine does not manufacture intervention assessments, resource estimates or nurse approval. The separate `provisional_patient_report` mode remains available for exact reviewed patient-fact mappings. The existing reference labels need not be revalidated, but labels alone do not supply those mappings.

## Earlier implementation history

## October 11: handbook rules and provisional ESI 3 for nurse review

The source-linked `EsiV4RuleCatalog` now includes concrete clinical specifications, exclusions and input limitations for A/B/C, plus a separately identified `ESI4-D-PROVISIONAL` study adaptation. `EsiV4RuleLayer` executes this branch in the selectable `handbook_pre_screening` mode. The clinical mode still requires measured vital signs. The current patient workflow remains `provisional_patient_report` until usable patient-fact mappings have been reviewed.

The draft is executable now:

```bash
php artisan esi:preview --no-interaction
php artisan esi:preview --json --no-interaction
```

The command demonstrates 11 fictional inputs covering all five levels and unresolved A/B/C conditions. JSON includes rule IDs, required fields, source references, approval status and evidence traces. It makes no provider calls, database writes or approval changes. A preview has `status=draft_preview`, `priority=null`, a separate `candidate_priority`, and `study_eligible=false`. Candidate levels cannot be counted as final results. Normal evaluation still enforces clinical approvals.

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

**Input boundary for the nurse:** the chatbot currently extracts verbatim excerpts from 15 patient fields. Those excerpts do not themselves establish `immediate_lifesaving_intervention_required`, the three B assessments, or `expected_esi_resources`. The clinical engine accepts documented assessments; preview vignettes supply them explicitly to test the rules. Such assessments cannot silently be added to the patient-only experiment. Nurse review must decide which exact reported conditions can support the required positive/negative facts and resource estimate. The rules are now specified; patient-fact mappings and clinical approval remain incomplete. Existing validated case labels can be retained.

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

The patient workflow now uses `ProvisionalEsiRuleLayer`. It can execute explicit, reviewed mappings without collecting measured vital signs. It records a provisional prediction and `decision_d.status = not_assessed`.

**No clinical mappings are approved or enabled.** The team confirmed that the workbook contains validated case labels without written rationales or general rule conditions. `config/esi.php` therefore has empty `provisional_rules` and `provisional_approvals`. Current patient submissions will continue to return Needs Review for Method B. Software tests use clearly fictional tokens and approvals; those must never become study criteria.

The existing `EsiV4RuleLayer` remains available under `esi.mode = clinical_esi_v4`. Its A–D assessments and measured-vitals requirement are unchanged. The default `provisional_patient_report` method is a separate study adaptation and cannot be described as complete clinical ESI v4.

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

The earlier source audit changed the specification guide only. The October 11 implementation above adds an executable handbook preview and the provisional D branch. It does not manufacture approvals or populate patient-report mappings with unsupported clinical assumptions.

## What the code checks

1. Facts must come from the accepted extraction and use the existing patient-field allowlist. The current rule scope requires an explicit adult age. Duplicate fields cannot classify.
2. Rules run in A, B, C order. A maps to 1; B to 2; C to 3, 4 or 5. A later rule cannot bypass an unresolved earlier candidate.
3. A B mapping requires explicit exclusion conditions for A. A C mapping requires exclusion conditions for both A and B. A failed match is never an exclusion.
4. Every condition checks the exact field state and a reviewed complete text value. Missing, unknown, not-applicable and explicitly absent remain distinct. No substring matching, implicit translation or inferred normal vitals occurs.
5. A C mapping needs a documented resource-related rationale. Tests and devices never become resource counts automatically. The software does not judge whether a clinician's rationale is medically adequate; that is the rule review's purpose.
6. Applicable conflicting priorities or unresolved required facts return Needs Review. Optional history does not automatically block a supported rule.
7. Approval must identify the reviewer, date, record, engine version and exact definition hash. Editing a rule invalidates the old approval. Full definitions and approval records affect the persisted rule version.
8. Both methods use the same accepted facts. Method B never receives Method A's label or explanation and never calls the LLM. Existing saved screenings remain unchanged.

Exact text matching is intentionally conservative and may have low coverage across different phrasing. Do not claim semantic matching. Add equivalent values only through development and review, without using held-out test wording to tune rules.

## Rule specification to complete with the reviewer

Prepare one row or record per general mapping. This is a review of the decision procedure, not a request to revalidate all case labels.

| Required item | What to record |
| --- | --- |
| Rule ID | Stable identifier independent of a dataset case ID |
| Decision point and output | A/1, B/2, or C/3–5, always provisional |
| Supporting conditions | Existing patient field, explicit state and accepted complete value(s) |
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
conditions: nonempty list of {field, state, values: [complete accepted strings]}
exclusions: {} for A; {A: conditions} for B; {A: conditions, B: conditions} for C
handbook_section: source chapter and printed page
adaptation_rationale: documented reason for the study adaptation
resource_rationale: required text for C; null for A/B
explanation: brief non-diagnostic result explanation
```

Conditions in a list are joined with AND; values within one condition are alternatives. At least one supporting condition must use the complaint or symptom fields. Accepted condition states are `reported`, `absent`, and `not_applicable`; uncertainty cannot satisfy a condition. Patient data cannot set approval or configuration.

Each corresponding entry in `esi.provisional_approvals` records `status`, `reviewer`, `reviewed_at` (YYYY-MM-DD), `record_id`, `rule_version`, and `definition_hash`. The engine version is `ProvisionalEsiRuleCatalog::VERSION`; the hash is calculated with `ProvisionalEsiRuleCatalog::definitionHash($definition)`. This hash links an approval to exact content; it is not proof of clinical review. Keep the underlying review document.

## Next steps in order

1. **Protect the existing reference dataset.** Preserve its labels. Have the custodian establish the paper's canonical-case development/test split before prompt or rule tuning. Do not expose final-test labels for rule construction.
2. **Complete the rule specification.** Use the handbook and development cases to draft general mappings, including ESI 3 and earlier-decision exclusions. Have the clinical reviewer approve those conditions and their provisional interpretation. Record rejected or unsupported mappings too. Do not invent review records or backfill rationales as if they were part of the original validation.
3. **Enter only approved mappings and pre-test development cases.** Start with the paper's 10 pilot cases in the development set. Examine matched rules, missing facts, no-match cases, unsupported phrasing and conflicts. Software tests may be run now with `php artisan test --compact tests/Unit/ProvisionalEsiRuleLayerTest.php tests/Feature/EsiRuleIntegrationTest.php tests/Unit/EsiV4RuleLayerTest.php`. They verify implementation, not clinical accuracy.
4. **Freeze the comparison.** Supply the same reviewed screening criteria to Method A's prompt as to Method B. The current Method A prompt remains exploratory and was deliberately not rewritten by this change. Freeze prompt, model/settings, schema, translations, rules, approvals and dataset split. Changing the study prompt creates a new version; preserve earlier A outputs.
5. **Build and verify the evaluation runner, then run all three repetitions.** The project does not yet have the paper's full batch runner or metric calculations. Each accepted response must yield a saved A/B pair; join reference labels only afterward. Keep run IDs and all technical attempts. The planned final set is 120 canonical cases × 5 versions × 3 runs = 1,800 paired observations, grouped under 120 cases.

Report each run and the mean across runs, without selecting the best run. Follow Section 3.13 for common-pair accuracy, macro-F1, error direction and consistency, alongside each method's coverage, Needs Review, technical failures and completion-adjusted accuracy. Report ESI 3 separately. If there are no valid A/B pairs, conditional comparative metrics are not estimable. Needs Review stays a null-priority abstention, not an ESI level or an over/undertriage event.
