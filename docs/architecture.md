# TriageFlow architecture

## Patient and staff interfaces

The patient route `/` renders only the chatbot. `/admin` and all staff patient/screening routes require a staff account. Existing researcher accounts are staff accounts; this local demo has one trusted staff permission level. Patient sessions are not Laravel user accounts and cannot enter staff pages.

`PatientChatController` resolves the current visit exclusively from Laravel's server-side browser session. No submitted stub or visit ID selects the patient record. `PatientChatService` assigns a unique sequential stub from the database-generated visit ID inside a transaction. `patient_visits` stores progress, original field answers and the scope confirmation time; `chat_messages` stores each message in order with its source (`guide`, `intake`, `patient`, `model` or `system`). Stub numbers are display references only.

`ConversationalInterview` manages six groups covering all 15 dataset fields. Each typed reply goes through `IntakeInterpreter`: a strict understanding-only schema returns verbatim evidence, field states, clarification flags and a next-question proposal. It has no triage priority or classification output. One reply can cover several fields, including volunteered information from later groups. For absent or not-applicable facts, the original containing sentence is retained so a short excerpt cannot discard its negation. Laravel applies grounded facts, selects only missing or incomplete fields, and accepts the proposed question only when its field list matches that plan. Otherwise it uses localized fallback questions.

A field can be asked at most twice. Skips and unresolved fields become unknown; existing partial answers remain available. Clarifications append the new evidence to the original report; explicit corrections replace the structured value while the original message remains in the transcript. Age must be an adult integer if reported. The final review panel allows corrections across all 15 fields and records before/after values when edited.

Each typed reply makes one provider attempt outside the database transaction. A failed call leaves the question unchanged and the draft in the composer. A successful turn saves the original reply, extracted facts, prompt/settings, returned model, raw response and usage in message metadata. Intake metadata is distinct from final screening metadata. Versioned `interview_state` stores pending fields, clarification fields and ask counts. Existing visits without this state continue through `PatientInterview` and `PatientFollowUp` in their original guided flow.

Each submission is checked against the expected field and message revision before and after the provider call. Browser-session request locks prevent concurrent writes. Finishing the interview and confirming the exclusions triggers `ScreeningService` once. A unique database constraint permits one screening per visit, and an atomic ready-to-screening transition prevents duplicate predictions.

Patient responses expose their own stub, progress, display messages and editable answers when ready for review. They do not contain provider metadata, raw API bodies, staff identity or integration settings. Staff use `PatientRecordController` to search stubs and inspect the full conversation, reported answers and linked triage. Their lists refresh every 10 seconds. Both interfaces reuse `ChatTranscript`; the final provider message is labeled separately from the intake guide.

Same-browser recovery lasts while the Laravel session is valid. Ending patient access forgets the visit pointer, preserving history for staff. The application has no stub-based recovery, patient registration or cross-device login. A crashed screening may remain in progress for staff inspection; no automatic second prediction is generated.

The latest user-approved scope adds patient and staff presentation to the fictional-case demo. It does not add a production patient portal, queue ordering or approved clinical rules.

## Request flow

```text
Researcher sign-in → intake validation → save original input + prompt/settings
                                       ↓
                            allowlisted patient JSON
                                       ↓
                         Laravel → OpenAI Responses API
                                       ↓
                           persist raw attempt response
                                       ↓
                      validate envelope, JSON and grounding
                              ↙                    ↘
               save accepted Method A       bounded retry / technical failure
                              ↓
                 same saved extracted facts → Method B ESI rules
                               ↓
                 save separate result, input and audit trace
                               ↓
                       display both saved results
```

`ScreeningController` delegates submission to `ScreeningService`. That service saves the session before any provider call. No queue worker is required: one browser submission waits for a bounded request sequence. A crashed process may leave a session as `processing`; the interface does not disguise it as Needs review.

Laravel authentication protects all staff research pages and submissions; public patient endpoints have separate session ownership, CSRF protection and rate limits. Researcher accounts are created through `researcher:create`, with hashed passwords and no public registration. CSRF middleware remains enabled, sign-in is limited to five attempts per minute, and screening submission to ten per minute. This is one trusted local research workspace, not a multi-tenant patient portal.

## Study interpretation

Method A uses GPT Luna to understand and decide preliminary triage. Method B receives only the extracted facts from the same accepted response, then the deterministic rule layer alone decides. It never receives the model priority, classification rationale, or a fallback from Method A. The conversational intake is a separate understanding-only component. Method B now persists its exact input and result; rules applicable to the permitted patient-reported facts still require definition, clinical review and implementation.

The attached manuscript's sections 1.5, 3.5, 3.10–3.11 and 3.14 informed the scope. No manuscript text was edited. Clinical rule definitions and review have not been completed. The historical v0.1/v0.2 prompts required Needs review with null priority. Following the October 6 request, `screening-v0.3-llm-baseline` enables exploratory LLM-only ESI estimates using pretrained model knowledge. It preserves unknown inputs and requires Needs review when a defensible estimate cannot be made. It does not enforce an approved study rule set. Both patient and structured research submissions use that version, and save `classification_mode=exploratory_llm_only` and `criteria_status=pending_clinical_review`. Patient explanations follow the chosen language; structured research explanations use English without sending the experimental language condition.

A valid model classification is stored verbatim even if it differs from the researcher's expectation. Validation checks the technical contract; it does not repair a prediction, measure correctness, or claim that a fact supports an ESI decision. The original accepted priority is preserved, including an unexpected classification; code does not replace it or retry to obtain a preferred level.

The ESI orientation in the exploratory prompt follows the general distinction between acuity/risk and anticipated resources described in the [ENA handbook overview](https://enau.ena.org/Listing/Emergency-Severity-Index-Handbook-5th-Edition-85744). This is not an implementation of the full clinical algorithm. Final thesis evaluation still requires reviewed criteria shared by both methods.

## Inputs and unknown values

`PatientFields::NAMES` is the sole provider-input allowlist. It contains the 15 patient-reported fields from section 3.5, including test names/request context and devices. A separate original input preserves submitted wording and blank values. The normalized provider input makes every omitted or blank allowed field explicit as `null`; reported negatives are left in their original wording.

Case ID, variant ID and researcher identity stay in application metadata. Patient chat sends the selected response language as a presentation setting; the structured research intake does not send its language condition. No answer key is accepted as a patient field. The service applies the allowlist again even if it is called outside the HTTP controller. No translation, spelling correction, extra clinical measurements or dataset import occurs.

Free text is still untrusted. The form requires confirmation that narrative fields exclude personal identifiers, measurements, examination findings, diagnostic result values and answer-key information. This is not a semantic data-loss-prevention system: researchers must review narrative content. The system prompt tells the model to ignore embedded instructions and excluded material. Mock tests verify message construction, not immunity to prompt injection.

Age may be unknown if the researcher confirms the fictional subject is an adult. A supplied age below 18 is rejected. Optional history can explicitly report present, absent, unknown or not-applicable information. Extracted facts record these states; missing input never becomes an absent finding automatically.

## OpenAI integration

The model identifier `gpt-6-luna` and support for Structured Outputs were verified in [official model documentation](https://developers.openai.com/api/docs/models/gpt-6-luna) on October 2, 2026. The [Structured Outputs guide](https://developers.openai.com/api/docs/guides/structured-outputs) documents `text.format` with `type: json_schema` and `strict: true` for Responses API requests. The client uses `POST https://api.openai.com/v1/responses`, with redirects disabled and credentials added only by Laravel.

`OPENAI_API_KEY` and `OPENAI_MODEL` are read only in `config/triage.php`. Blank configuration sends no request. The UI receives only a configured flag, model name and prompt version; no key is exposed. Requests set `store: false`, reasoning effort `low` and output limits of 2,500 tokens for intake and 4,000 for final screening. There are no tools or retrieval calls.

Temperature and top-p are omitted. Model-specific support for temperature zero with this reasoning setting was not established during implementation; the omission is recorded explicitly rather than sending an assumed parameter. Freeze reviewed settings before evaluation. There is no verified dated snapshot pinned here, and account access can change independently of this checkout. A returned identifier is saved without rewriting it.

Each call has a five-second connection timeout and a 30-second overall timeout. Intake makes one attempt per typed reply. Final screening allows at most three attempts. Connection failures, 429 responses, 5xx responses and malformed/incomplete outputs can retry, with a bounded delay of 500 ms then 1,000 ms by default. Valid classified/needs-review outputs stop the sequence immediately. Refusals, credential failures and other rejected requests do not retry. No priority-based retry selection exists.

## Output validation

`ScreeningOutput` defines the strict JSON schema and separately validates provider output. Accepted responses require:

- A completed response envelope with a returned model identifier and one output-text message.
- Exactly the defined output fields, supported statuses, list-shaped facts/missing information and a nonempty brief explanation.
- An integer priority from 1 through 5 for `classified`, or exactly null for `needs_review`.
- Allowed fact fields and states. Reported/absent/not-applicable values and evidence must be the same verbatim excerpt of their source field. Unknown values and evidence are null.

Quotation checks establish textual traceability only. They do not prove semantic correctness, clinical relevance, absence of diagnoses in generated prose, or medical safety. Those limits remain research questions and review responsibilities.

## Persistence and pairing

`screening_sessions` stores UUID, case/variant metadata, original and normalized patient input, application status, Method A status/priority, unchanged accepted response bytes, parsed output, requested/returned model, prompt text/version, output schema and request settings, timestamps, total elapsed time, token usage and sanitized failure codes/messages.

`screening_attempts` preserves each received HTTP-success body before parsing, including malformed responses. It records attempt number, request start/completion, HTTP status, elapsed time, parsed accepted output and failure code. HTTP error bodies and exception messages are excluded because they may contain provider diagnostics or credentials. No authorization headers are logged or persisted. Failed HTTP-success responses remain in attempt history, never promoted to Method A.

There are no edit or overwrite endpoints for completed screenings. A new intake creates a new record. `RuleLayer` is bound to `EsiV4RuleLayer`. After saving an accepted Method A response, `ScreeningService` passes that response's unchanged `extracted_facts` to the rule engine. It saves `method_b_priority`, `method_b_rule_version`, `method_b_input` and `method_b_result`, including the explanation, flags, matched IDs and trace. A rule-engine exception records a Method B technical failure without discarding Method A or retrying the provider. New sessions without an accepted model response have `not_evaluated` for Method B. Existing `not_implemented` records are retained as historical records.

### ESI v4 decision inputs and approvals

The study is explicitly before professional assessment. Manuscript sections 1.5, 3.5 and 3.11 exclude measured vital signs, examination findings and diagnostic result values. These are deliberate exclusions, not missing features to add. The implemented standard ESI engine and the study's proposed ESI-based screening rules are distinct: the latter require a clinically reviewed mapping from permitted patient reports to supported preliminary decisions. That mapping has not been implemented. A complete standard Decision D cannot run within the study inputs; its omission cannot automatically establish ESI 3.

`EsiV4RuleCatalog` documents six rules covering A, the three B criteria, C and D. Each entry includes the decision point, required observations, condition, source, reviewer approval and rule version. Approval records come only from server configuration in `config/esi.php`. An approved status without a reviewer, valid review date, record reference and matching definition version (`EsiV4RuleCatalog::VERSION`) cannot activate a rule. All application approvals are initially pending. The saved rule revision combines this definition version with a hash of the approval records. Changing an approval creates a distinct revision, and traces retain the approval details actually used.

The engine checks adult eligibility from the saved age fact, then follows A → B → C → D. It never interprets symptom text as an assessed clinical finding. The optional second argument of `evaluate($understoodFacts, $clinicalAssessment = [])` describes a clinical assessment contract outside this study. It is exercised by fictional engine tests, but the screening service always supplies an empty assessment and public input cannot populate it. Activating this contract would not resolve the study's need for rules that work on its permitted inputs.

For reference, the engine's clinical observation keys are `immediate_lifesaving_intervention_required`, `high_risk_situation`, `acute_confusion_lethargy_disorientation`, `severe_pain_or_distress`, `expected_esi_resources`, `heart_rate`, `respiratory_rate`, and `oxygen_saturation`. These are not additional thesis input fields. Each observation has a `state` and `value`. A `reported` assessment also requires `evidence`, `source`, `recorded_by`, `recorded_at` (RFC 3339, for example `2026-10-06T08:00:00+08:00`) and `record_id`. Boolean assessments use actual booleans, including an explicit `false` to exclude a criterion. Resource estimates use a nonnegative integer of expected distinct ESI resources for the current visit. Their source must be `clinical_assessment`. Vital signs require source `measured` and units `beats/min`, `breaths/min`, or `%`. Unknown, missing, not-applicable and contradictory observations remain distinct in the trace and cannot count as negative assessments.

No resources are inferred from tests already completed/requested or devices. Pain ratings alone do not establish Decision B. B's mental-status observation means an acute change, not chronic baseline confusion. A supported positive B criterion suffices after A is negative; all three B criteria must be explicitly negative and approved before moving to C. At D, HR >100, RR >20 or saturation <92 triggers `consider_esi_2` with Needs review and null priority. A valid abnormal measurement can flag consideration even if another is unavailable, but ESI 3 requires all three valid measured values outside those danger zones. Missing evidence or approval stops a decision instead of defaulting to a priority.

The input snapshot retains the original extracted-fact states and omitted entries. No normalization changes Method A. The rule engine contains no model calls, clock-dependent choices or random values. Given identical facts, assessment, approval records and rule version, it returns the same result. The study inputs intentionally cannot complete clinical ESI. The next study task is review of rules applicable to those permitted inputs, including which proposed ESI-based levels are defensible and when Needs review is required. Nurse reference labels remain separate from case inputs and must not be passed to either method.

## Dataset boundaries

No evaluation dataset, label import, batch runner or performance metrics are included. The planned dataset remains 150 canonical cases × five variants: 30 development cases/150 inputs and 120 held-out cases/600 inputs. All variants must stay with their canonical case's split. IDs are storage metadata, not prompt content. A future evaluation runner must join answer keys only after predictions are saved, freeze versions and preserve case grouping across repeated runs and uncertainty analysis.

The few test fixtures in `tests/Support` are temporary fictional integration examples. They do not belong to either planned split. Automated tests use in-memory SQLite. Small live browser checks create explicitly fictional demo visits, retained for staff inspection. Normal migrations/seeders insert no records.
