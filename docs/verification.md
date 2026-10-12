# Verification record

Updated October 12, 2026 (Asia/Manila). Automated API tests use mocked responses and in-memory SQLite. Earlier live fictional browser checks are retained below; no dataset batch was run.

## FirstLook project rename — October 12

- Updated active app labels, all three chatbot translations, package metadata, the default application name and Docker services to FirstLook. New patient stubs use `FL-`.
- Created `screening-v0.4-llm-baseline`, `intake-v3-conversational` and `patient-voice-v2-casual` with the new branding. Older prompt files, existing stubs and saved responses remain unchanged. The intake interpreter now reads the configured prompt version.
- Retained the existing local database and dependency volumes through local environment settings, and preserved the browser session cookie. Verified the existing nurse login and staff dashboard over HTTP after starting the FirstLook containers; the patient page also returned HTTP 200. These checks did not call OpenAI or submit a patient case.
- Full PHPUnit suite: **253 tests, 4,292 assertions passed**. Laravel Pint, TypeScript checking, Vite production build and `git diff --check` passed. Composer metadata validation passed with the existing warnings about intentionally pinned package versions; dependency versions were unchanged.
- The project remote is `https://github.com/reanneangcos/FirstLook`. Local environment secrets, accounts and databases are excluded from Git.

## Supplied handbook implementation — October 12

- Inspected the supplied 95-page `Esi_Handbook.pdf`: ESI v4, May 2005, AHRQ 05-0046-2. Visually checked the algorithm, immediate-intervention table and resource table/examples. The catalog now records this edition, its file fingerprint and the correct chapter/page references.
- Added verified immediate-intervention tokens from Table 3-1 and deterministic counting of an explicitly supplied expected-resource plan from Chapter 5. Neither input is inferred from patient messages, prior/requested tests or existing devices. Unknown service types, incomplete zero/one-resource plans and contradictory assessments require review.
- Selected `handbook_pre_screening` as the default. Provisional ESI 3 still requires supported negative A/B and at least two expected resources; D remains unassessed. Normal evaluation retains approval checks. The patient workflow still has no approved mapping or assessment source and cannot classify solely because this mode is selected.
- Expanded the isolated preview to 18 fictional cases. Focused rule, preview, integration and screening regression suite: **138 tests, 729 assertions passed**. Laravel Pint passed. No real API call, database migration, reference-label edit or historical-result rewrite was performed.

## Handbook draft and provisional ESI 3 — October 11

- Added handbook clinical specifications, exclusions and source sections to the catalog. The selectable `handbook_pre_screening` mode returns provisional 3 only after explicit negative A/B assessments and an established estimate of at least two expected resources. Missing or conflicting A/B/C inputs still return Needs Review. The separate clinical mode continues to require Decision D measurements.
- Added `php artisan esi:preview --no-interaction` and its JSON option. Eleven fictional examples exercise all five candidate levels and unresolved decisions. Preview outputs have null study priority, retain pending approval records and are explicitly ineligible for study scoring. The command makes no provider calls or database writes.
- Focused unit and feature suite: **105 tests, 633 assertions passed**, covering both engines, preview isolation, application binding, persistence and unchanged Method A results. Laravel Pint and `git diff --check` passed.
- Generated the four-page `triageflow-handbook-rules-nurse-review.pdf` with the decision diagram, clinical conditions, input limitations, nurse-review questions and final-testing treatment of abstentions. Rendered and visually checked every page.
- No clinical approvals or patient-report mappings were fabricated or activated. The patient workflow remains on its existing provisional engine with empty mapping configuration; it still abstains where the required decision facts are unavailable. The draft implements the decision procedure, but does not establish patient-symptom mappings or clinical validity.

## Provisional patient-report rule path — October 11

- Added a separate provisional engine and catalog, selected by the current patient workflow. Kept the clinical ESI v4 engine unchanged and explicitly selectable.
- Full native suite: **185 tests, 3,613 assertions passed**. New checks cover all five provisional levels using fictional approved fixtures, explicit earlier-decision exclusions, missing states, conflict abstention, changed-definition approval invalidation, exact text matching, evidence validation, and independence from Method A's priority.
- Laravel Pint, TypeScript checking, the Vite production build and `git diff --check` passed. No provider call, clinical mapping activation, dataset evaluation, historical prediction rewrite or database migration was performed.
- The workbook was confirmed to contain validated labels only. Provisional mappings and approvals remain empty, so current Method B submissions still abstain. Test approvals are software fixtures, not clinical evidence.
- Updated the working thesis copy and [rule-layer guide](rule-layer-guide.md) to distinguish methodological support from clinical rule approval and to specify pre-testing requirements. Rendered the Word file and visually checked all 40 pages.

## ESI v4 rule layer — October 6

- Connected the deterministic A–D engine to new screenings using the same accepted extracted facts. Method A and original response bytes remain unchanged. Historical screenings are not reprocessed.
- Focused rule, integration, screening, patient chat, localization and conversational-intake suite: **111 tests, 1,991 assertions passed**. Tests cover all five ESI branches with fictional explicit assessments, strict adult vital-sign boundaries, review flags, missing/unknown/not-applicable/contradictory values, pending approvals, provenance validation, revision changes and independent persistence of both methods.
- Clinical approval and assessment fixtures exist only in tests. Production approvals remain empty. The current chat collects no clinical assessments, resource estimates or measured vitals, so Method B returns Needs review for unsupported decisions. Tests verify software behavior, not clinical validity.
- The additive Method B migration was applied to the local Docker database. No existing predictions or conversations were replaced, and no live OpenAI call or dataset evaluation was needed for this change.

## Exploratory LLM-only classification — October 6

- Activated `screening-v0.3-llm-baseline` for patient and structured research submissions. Historical v0.1/v0.2 prompt files and previously saved outcomes remain unchanged.
- New records retain `classification_mode=exploratory_llm_only` and `criteria_status=pending_clinical_review`. Patient results identify the exploratory mode in English, Bisaya or Tagalog; staff records retain the mode with the original response.
- Screening, patient chat, localization, conversational intake, schema and translation tests passed (108 tests across the focused files). The new historical-record test initially selected the older record when timestamps tied; its lookup was corrected and the screening test file passed again. PHP formatting, TypeScript checking and Vite production build passed.
- Classification and Needs-review handling were checked with mocked provider responses, including retaining the original priority and keeping Method B unimplemented. These checks verify application behavior, not clinical accuracy.
- Live patient case **TF-000008**, explicitly labeled a fictional software test, completed intake and returned **ESI 2** from the configured API. The patient chat displayed the priority, explanation and exploratory-mode label. No expected priority or answer key was supplied in the case. This confirms the classification path works; it does not validate that prediction clinically.

## Conversational intake — October 4

- Focused Docker suite: **88 tests, 2,357 assertions passed**, covering conversational intake, legacy patient chats, localization, final screening and dataset question mappings.
- TypeScript check, Vite production build, Laravel Pint and `git diff --check` passed. The additive migration for interview state/message metadata was applied without replacing existing records.
- Live Bisaya browser case **TF-000006**: one mixed-language reply covered 12 dataset fields; the next question asked only for the missing medication name. A second reply supplied that name and the remaining test/device details. The bot did not repeat age, complaint, timing or severity questions.
- An initial live request failed; the question and draft remained available. A manual retry succeeded. Sanitized intake failure codes are now logged without the reply, credentials or raw provider errors.
- Review exposed a shortened negative excerpt that had lost its negation. The prompt now requests complete negative clauses, and the application retains the original containing sentence for absent/not-applicable facts. A regression test reproduces that exact Bisaya example. The review panel uses multiline fields.
- The live case was reviewed and corrected before submission. Final screening succeeded and displayed a Bisaya Needs-review explanation, as required by the provisional screening prompt. The original replies remain in the transcript alongside a correction audit record.
- Staff sign-in and the saved history for TF-000006 were checked in the browser. The reported-information panel showed the corrected age (35), the restored negative wording and all 15 fields, alongside the original conversation and linked screening.
- Intake output contains facts and a next-question proposal, with no triage decision field. Method B remains unimplemented; its future rule interface accepts understanding-only facts and excludes Method A’s result.

## Original automated checks — October 3

| Check | Result |
| --- | --- |
| Native `php artisan test --compact` | 46 tests, 420 assertions passed |
| Docker `php artisan test --compact` | 46 tests, 420 assertions passed |
| `npm run build` | TypeScript check and Vite production build passed |
| PHP formatting with Laravel Pint | Passed |
| `composer validate --no-check-publish` | Valid; warns about deliberately exact framework/adapter version pins |
| npm dependency audit during installation | No vulnerabilities reported at installation time |

Feature and unit tests cover:

- Valid classifications, all five priority values, and Needs review with null priority.
- Preserving the original accepted prediction and response without a priority-based retry.
- Unknown values, exact patient wording and reported facts with source excerpts.
- Malformed JSON/envelopes, invalid status/priority pairs, refusals and incomplete output.
- Bounded retries for timeouts, rate limits, provider errors and malformed responses.
- Missing credentials, rejected credentials and sanitized technical failure records.
- Strict patient-field allowlisting, excluded answer-key fields and separate case/variant metadata.
- Treating instruction-like patient text as data when constructing messages.
- Adult/synthetic scope confirmation, researcher authentication and rate limits.
- Stored dashboard counts, searchable history and session details.

Tests use `Http::preventStrayRequests()` and an isolated in-memory SQLite database. PHPUnit forces both environment and server variables to use that database. The test base class refuses to start database refreshes unless the application is in testing mode with in-memory SQLite. Docker supplies database paths through both variable sources, so forcing environment variables alone was insufficient.

## Docker checks

Built the PHP image, installed the locked dependencies, migrated the database and started both actual services, `app` and `node`. Confirmed both were running and the frontend built inside the Node container. Restarted both services and verified the database's migration history remained intact. The original verification used an empty normal database. Later, an explicitly requested staff account was created. QA conversations use a separate temporary database. During final verification, a Docker environment-precedence bug caused database tests to reset the application database and remove the staff account. The isolation configuration was corrected, a pre-migration guard was added, and the account was restored with its previously supplied credentials. The cause was verified in PHPUnit and Laravel’s installed environment-loading code.

The Compose project name is `triageflow-thesis2`. It keeps this checkout's containers and volumes separate from the older `THESIS` checkout. Ports 8000 and 5173 bind to loopback only.

## Browser checks

Used an isolated temporary SQLite database and a temporary researcher account. Browser records included one clearly labeled mocked Needs-review fixture and one fictional form submission with API configuration blank. These records were not inserted into the normal native or Docker databases.

Checked sign-in, dashboard counts, intake labels and confirmation errors, submission to a saved technical-failure record, case-ID search, links to session details, source facts, unknown fields, and the explicit Method B “Not implemented” state. Reviewed desktop screenshots and 390 × 844 phone layouts. Fixed a hidden table label that caused whole-page horizontal overflow; history, intake and details then matched the phone viewport width. Wide record tables scroll within their container.

## Limits and pending work

- Small fictional live checks confirmed API access for those requests on October 4. They do not establish dataset performance or clinical validity. Model information is recorded in [versions](versions.md).
- Exploratory classification now uses pretrained model knowledge. Final study evaluation still requires reviewed classification criteria shared by both methods. Mocked tests validate storage and the response contract, not clinical decisions.
- Method B now has both provisional and clinical rule engines with evidence traces. Approved patient-report mappings remain pending; see the October 11 verification above.
- No final dataset was imported, split, evaluated or used for performance claims. The planned 150 canonical cases and their variants remain outside setup.
- Narrative exclusions require researcher review. Quotation validation does not establish clinical accuracy, prevent every diagnosis in generated prose, or prove resistance to prompt injection.
- Processing is synchronous. A process interruption may leave a record marked Processing; automatic recovery is not implemented.
- The revised working manuscript is in `docs/TriageFlow-Thesis-RRL-Revised.docx`. The application was not deployed.

## Patient and staff extension

Added feature coverage for public chat entry, unique automatic stubs, same-session recovery, unknown answers, adult validation, stale/duplicate answers, completed-chat screening, duplicate-submission prevention, patient isolation, protected staff access, stub search, transcript links, ending browser access without deleting records, and hiding staff settings from patient pages. Rate-limit counters for intake replies and screening submissions are separate.

At the time of the original extension, the API ran only at final screening submission. The October 4 conversational update adds one API attempt per typed intake reply; starting and skipping remain local. Existing final screening validation/retry tests remain in the suite.

The patient browser walkthrough completed all 15 questions, saved the missing-configuration outcome, and recovered the same completed stub after a reload. The 390-pixel mobile chat had no whole-page horizontal overflow.

Staff browser QA confirmed sign-in, stub search, the complete transcript, reported-field summary and the link to the full triage record. Patient chat, staff list and staff conversation pages matched a 390-pixel viewport without whole-page overflow. No JavaScript errors were reported during the completed walkthrough.

After the isolation fix, the full Docker suite passed with 46 tests and 420 assertions. The application database contained one staff account both before and after that run, confirming that the restored account survived.
