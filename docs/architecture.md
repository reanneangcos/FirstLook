# TriageFlow architecture

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
                      display saved session
                              ↓ future work only
                      approved Method B rules
```

`ScreeningController` delegates submission to `ScreeningService`. That service saves the session before any provider call. No queue worker is required: one browser submission waits for a bounded request sequence. A crashed process may leave a session as `processing`; the interface does not disguise it as Needs review.

Laravel sessions protect all research pages and submissions. Researcher accounts are created through `researcher:create`, with hashed passwords and no public registration. CSRF middleware remains enabled, sign-in is limited to five attempts per minute, and screening submission to ten per minute. This is one trusted local research workspace, not a multi-tenant patient portal.

## Study interpretation

The attached manuscript's sections 1.5, 3.5, 3.10–3.11 and 3.14 informed the scope. No manuscript text was edited. Clinical rule definitions and review have not been completed. `screening-v0.1-provisional` consequently requests Needs review with null priority; it contains no invented medical thresholds. The output contract supports ESI 1–5 so future reviewed prompts can use it. Tests exercise these categories using clearly fictional responses.

A valid model classification is stored verbatim even if it differs from the researcher's expectation. Validation checks the technical contract; it does not repair a prediction, measure correctness, or claim that a fact supports an ESI decision. Thus, if the model returns a technically valid classification despite the current prompt's request for review, it is retained as the original provisional Method A output for inspection.

## Inputs and unknown values

`PatientFields::NAMES` is the sole provider-input allowlist. It contains the 15 patient-reported fields from section 3.5, including test names/request context and devices. A separate original input preserves submitted wording and blank values. The normalized provider input makes every omitted or blank allowed field explicit as `null`; reported negatives are left in their original wording.

Case ID, variant ID, language condition and researcher identity stay in application metadata. No answer key is accepted as a patient field. The service applies the allowlist again even if it is called outside the HTTP controller. No translation, spelling correction, extra clinical measurements or dataset import occurs.

Free text is still untrusted. The form requires confirmation that narrative fields exclude personal identifiers, measurements, examination findings, diagnostic result values and answer-key information. This is not a semantic data-loss-prevention system: researchers must review narrative content. The system prompt tells the model to ignore embedded instructions and excluded material. Mock tests verify message construction, not immunity to prompt injection.

Age may be unknown if the researcher confirms the fictional subject is an adult. A supplied age below 18 is rejected. Optional history can explicitly report present, absent, unknown or not-applicable information. Extracted facts record these states; missing input never becomes an absent finding automatically.

## OpenAI integration

The model identifier `gpt-6-luna` and support for Structured Outputs were verified in [official model documentation](https://developers.openai.com/api/docs/models/gpt-6-luna) on October 2, 2026. The [Structured Outputs guide](https://developers.openai.com/api/docs/guides/structured-outputs) documents `text.format` with `type: json_schema` and `strict: true` for Responses API requests. The client uses `POST https://api.openai.com/v1/responses`, with redirects disabled and credentials added only by Laravel.

`OPENAI_API_KEY` and `OPENAI_MODEL` are read only in `config/triage.php`. Blank configuration sends no request. The UI receives only a configured flag, model name and prompt version; no key is exposed. Requests set `store: false`, reasoning effort `low` and a 4,000-token output limit. There are no tools or retrieval calls.

Temperature and top-p are omitted. Model-specific support for temperature zero with this reasoning setting was not established during implementation; the omission is recorded explicitly rather than sending an assumed parameter. Freeze reviewed settings before evaluation. There is no verified dated snapshot pinned here, and access for the user's account has not been checked. A returned identifier is saved without rewriting it.

Each call has a five-second connection timeout and a 30-second overall timeout. The application allows at most three attempts. Connection failures, 429 responses, 5xx responses and malformed/incomplete outputs can retry, with a bounded delay of 500 ms then 1,000 ms by default. Valid classified/needs-review outputs stop the sequence immediately. Refusals, credential failures and other rejected requests do not retry. No priority-based retry selection exists.

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

There are no edit or overwrite endpoints for completed sessions. A new intake creates a new record. Method B is explicitly `not_implemented`, with no hybrid priority. `RuleLayer::evaluate(ScreeningSession $savedSession)` is the future integration boundary; `PendingRuleLayer` performs no clinical decision and no provider call. Future implementation must store its own versioned result and trace separately, after Method A persistence.

## Dataset boundaries

No evaluation dataset, label import, batch runner or performance metrics are included. The planned dataset remains 150 canonical cases × five variants: 30 development cases/150 inputs and 120 held-out cases/600 inputs. All variants must stay with their canonical case's split. IDs are storage metadata, not prompt content. A future evaluation runner must join answer keys only after predictions are saved, freeze versions and preserve case grouping across repeated runs and uncertainty analysis.

The few test fixtures in `tests/Support` are temporary fictional integration examples. They do not belong to either planned split. Browser verification uses a separate temporary database. Normal migrations/seeders insert no records.
