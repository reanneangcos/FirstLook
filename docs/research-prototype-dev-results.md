# FirstLook: completed prototype and DEV findings

> **Historical record (21 October 2026, rule version 1.1.0, 32 profiles, prompt v4).** The first frozen TEST study used rule version `research-esi-patient-report-2.0.0` (70 profiles) and prompt `screening-study-v8-research`; the second used version 2.1.0 (92 profiles) and prompt `screening-study-v9-research`, both with a 60-second request timeout. The final DEV run on the 2.0.0 configuration is reported in Section 3.9 of the thesis and saved under `storage/app/private/studies/research-prototype-2026-10-21/dev-v8-*`.

21 October 2026. **The software is ready for a frozen prototype experiment. TEST has not been run.**

## What was completed

- Activated the research prototype engine with 32 deterministic profiles: four at Decision A, fifteen at B and thirteen resource profiles at C. Decision D remains unassessed because this study does not collect vital signs.
- Kept language interpretation in Luna. The engine consumes evidence-linked concepts rather than language-specific phrases or spelling lists.
- Preserved Method A's accepted prediction unchanged; Method B receives only the accepted extracted and normalized facts. It never receives A's prediction or rationale as a decision input.
- Added checks for malformed or contradictory facts, unsupported symptoms, unmatched danger signals and unsupported resource estimates. Missing facts remain missing; an incomplete resource profile does not default to ESI 3.
- Made all numerical outputs explicitly provisional, including the ESI 3 message requiring nurse evaluation and vital signs. The admin screens show the result and rule trace.
- Removed clinical-approval execution gates in research mode. `unreviewed` is not a result status. Research provenance is retained without inventing clinical approval. Historical approved definitions and saved results remain intact.
- Completed engine and integration tests, including independent A/B outputs, database histories, DEV-only input enforcement and deterministic behavior. The full suite passed: **370 tests, 6,115 assertions**. The TypeScript/Vite build and PHP formatting passed.

The [rules and sources guide](research-prototype-rules.md) explains the exact adaptation and contains the complete diagram. The [catalog export](research-rule-catalog.json) lists every condition, source section and rule ID.

## What was actually run

Two separate DEV checks used the original structured patient fields, not the separate paragraph dataset:

| Check | Inputs | Purpose | Run ID |
|---|---:|---|---|
| Paired pilot | 10 canonical cases × 5 language versions = 50 | Check both methods across the multilingual pilot | `research-paired-pilot-2026-10-21` |
| Additional coverage | Other 20 DEV cases, English only = 20 | Check the remaining development presentations | `research-dev-coverage-2026-10-21` |

Together they covered all 30 canonical DEV cases. They are reported separately because their language sampling differs. This was one pass, not the final three-repetition experiment. The existing earlier pilot was preserved.

The 50-input pilot included English, Tagalog, Bisaya and the three possible mixed-language conditions. Each case has five versions: three single-language versions and two mixed-language versions. It does not have six versions per case.

All **70 inputs have database screening records, patient stubs and three-message submission/result histories**, visible through the staff patient dashboard. These are identified as structured dataset submissions, not simulated multi-turn conversations. The histories retain both Method A and Method B outcomes.

There were **74 API attempts for 70 inputs**. Four responses failed grounding validation and were retried under the existing bounded retry policy: three `ungrounded_fact` failures and one `ungrounded_normalized_fact` failure. All four recovered. There were **zero terminal technical failures**. Valid Needs Review outputs were retained and were not retried to obtain a preferred answer.

## Pilot: 50 inputs

| Measure | Method A: Luna alone | Method B: Luna facts + rules |
|---|---:|---:|
| Numerical ESI predictions / all inputs | 35/50 (70%) | 46/50 (92%) |
| Needs Review / all inputs | 15/50 (30%) | 4/50 (8%) |
| Terminal technical or upstream failures | 0 | 0 |
| Correct predictions / all inputs | 35/50 (70%) | 46/50 (92%) |
| Accuracy on the same 33 numerical pairs | 33/33 (100%) | 33/33 (100%) |
| Five-class macro-F1 on those 33 pairs | 1.000 | 1.000 |
| Undertriage on those 33 pairs | 0/33 | 0/33 |
| Overtriage on those 33 pairs | 0/33 | 0/33 |
| Same numerical prediction across all five versions | 5/10 cases (50%) | 8/10 cases (80%) |

Only 33/50 inputs had a numerical prediction from both methods. Their reference-class counts were ESI 1: 10, ESI 2: 2, ESI 3: 6, ESI 4: 8 and ESI 5: 7. Both common-pair confusion matrices were diagonal with those counts.

The 100% conditional accuracy describes these **33 selected DEV pairs**, not all inputs, unseen TEST performance, clinical accuracy or safety. The principal observed difference was numerical coverage: Method B classified 11 more inputs overall. Two inputs had only an A prediction, thirteen had only a B prediction and two had neither.

### Numerical coverage by language

| Language condition | Inputs | Method A | Method B |
|---|---:|---:|---:|
| English | 10 | 7 | 9 |
| Tagalog | 10 | 6 | 9 |
| Bisaya | 10 | 7 | 9 |
| English–Tagalog | 7 | 4 | 7 |
| English–Bisaya | 7 | 7 | 7 |
| Tagalog–Bisaya | 6 | 4 | 5 |

### Remaining pilot gaps

- **P001-V2:** an unrecognized symptom concept blocked resource classification.
- **P001-V3 and P001-V5:** the normalized evidence did not complete the upper-respiratory resource profile; the engine retained Needs Review.
- **P062-V1:** an unrecognized symptom concept blocked classification.
- Method A abstained on fifteen inputs, including several for which its extracted facts supported a deterministic B profile. These accepted A outputs were preserved.

These are genuine development findings. Normalization can still omit or fail to map information even when a person can understand the source text. This run does not establish perfect language understanding.

## Additional coverage: 20 English inputs

| Measure | Method A | Method B |
|---|---:|---:|
| Numerical predictions / all inputs | 12/20 (60%) | 18/20 (90%) |
| Needs Review / all inputs | 8/20 (40%) | 2/20 (10%) |
| Terminal technical or upstream failures | 0 | 0 |
| Correct predictions / all inputs | 11/20 (55%) | 17/20 (85%) |
| Accuracy on the same 12 numerical pairs | 11/12 (91.67%) | 11/12 (91.67%) |
| Five-class macro-F1 on those 12 pairs | 0.7714 | 0.7714 |
| Undertriage on those 12 pairs | 0/12 | 0/12 |
| Overtriage on those 12 pairs | 1/12 (8.33%) | 1/12 (8.33%) |

The common-pair reference counts were ESI 1: 4, ESI 2: 3, ESI 3: 1, ESI 4: 3 and ESI 5: 1. All agreed except the ESI 3 reference below, predicted as 2 by both methods.

- **P149-V1:** reported head injury and associated symptoms have no supported resource profile in this catalog. Both methods returned Needs Review.
- **P038-V1:** persistent cough did not match a complete profile. A requested chest X-ray was not automatically converted into an expected resource count. Both methods returned Needs Review.
- **P116-V1:** the reference is ESI 3; both methods predicted ESI 2. Method B matched the fever-during-chemotherapy high-risk profile. The handbook discusses this situation in Decision B. The reference label and the rule were retained unchanged; the observed disagreement counts as overtriage relative to the dataset. It is not silently reclassified as a correct answer. The source basis is documented in the catalog under `RP-B-CHEMOTHERAPY`.

## Scoring and integrity checks

The application scorer and an independent calculation agreed on counts, common-pair accuracy, macro-F1, undertriage and overtriage for both checks.

For every completed record, the saved A status/priority exactly matched the accepted LLM JSON. The saved B input contained only `extracted_facts`, `normalized_facts` and an empty `clinical_assessment`; no A prediction or reference label was supplied to the engine. Every input was marked DEV. Reference answers were joined locally for scoring after predictions were saved.

Needs Review is an abstention with null ESI. It reduces coverage and correct-predictions/all-inputs accuracy. It is neither ESI 6 nor automatically an undertriage or overtriage error. Undertriage means a predicted numerical ESI greater than the reference; overtriage means a smaller number. Both methods' main conditional metrics use the same numerical pairs.

These checks do not provide three-run repeatability, final-test confidence intervals or a valid unseen-data comparison. Language versions from the same case are not independent patients. Host CPU and peak-memory benchmarking were not performed in these DEV checks.

## Reproduction records

- Rule version: `research-esi-patient-report-1.1.0+0c06116484f3c2e2`
- Prompt version: `screening-study-v4-research`
- Normalization version: `patient-concepts-v2-research`
- Model requested: `gpt-6-luna`
- Active mode: `research_prototype`
- Readiness command: `study:check` returned `ready: true`, 32 rules and no configuration blockers.

Detailed local artifacts are retained under `storage/app/private/studies/research-prototype-2026-10-21/`: configuration snapshot; input manifests; full session/attempt exports; separate pilot and coverage CSVs; independent analysis; and application metric reports. Raw study data remain outside version control.

The new shared prompt and research criteria differ from the earlier pilot configuration. Preserve both runs as development history; do not claim that a single isolated code fix caused the entire improvement.

## Next steps for the final experiment

1. Describe the current research adaptation accurately in Chapter 3: positive-danger screening, fixed resource hypotheses, unassessed missing risks and provisional ESI 3. This implementation task did not edit the manuscript.
2. Retain this pilot record, the dataset release record and the observed exceptions. Select the secondary analysis repetition before seeing TEST outputs.
3. Freeze the TEST input manifest, separate reference-key hash, prompts, model/settings and rule version with `study:freeze`. This locks the configuration; later changes require a separate experiment.
4. Run the existing paired runner once for each repetition, `study:run … --run=1`, `--run=2` and `--run=3`. Each input produces A and B from the same accepted facts. Keep failures and abstentions in the records.
5. Score after all repetitions using `study:score`. Report per-run and mean paired accuracy/F1, undertriage, overtriage, coverage, Needs Review, technical failures, completion-adjusted accuracy, language consistency and repeatability, with the prespecified uncertainty analysis. Those unseen TEST findings belong in Chapter 4.

The prototype is executable now. Its 32 profiles cover selected presentations; this is not a complete clinical implementation of ESI and does not cover every possible condition.
