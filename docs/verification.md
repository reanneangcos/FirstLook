# Verification record

Completed October 3, 2026 (Asia/Manila). All screening API responses used for automated verification were mocked. No paid batch or live OpenAI request was made.

## Automated checks

| Check | Result |
| --- | --- |
| Native `php artisan test --compact` | 34 tests, 170 assertions passed |
| Docker `php artisan test --compact` | 34 tests, 170 assertions passed |
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

Tests use `Http::preventStrayRequests()` and an isolated in-memory SQLite database. The test configuration forces that database even inside Docker, where the normal application has a persistent database path.

## Docker checks

Built the PHP image, installed the locked dependencies, migrated the database and started both actual services, `app` and `node`. Confirmed both were running and the frontend built inside the Node container. Restarted both services and verified the database's migration history remained intact. The normal Docker database contains zero researchers and zero screening records after QA; account creation is an explicit setup step.

The Compose project name is `triageflow-thesis2`. It keeps this checkout's containers and volumes separate from the older `THESIS` checkout. Ports 8000 and 5173 bind to loopback only.

## Browser checks

Used an isolated temporary SQLite database and a temporary researcher account. Browser records included one clearly labeled mocked Needs-review fixture and one fictional form submission with API configuration blank. These records were not inserted into the normal native or Docker databases.

Checked sign-in, dashboard counts, intake labels and confirmation errors, submission to a saved technical-failure record, case-ID search, links to session details, source facts, unknown fields, and the explicit Method B “Not implemented” state. Reviewed desktop screenshots and 390 × 844 phone layouts. Fixed a hidden table label that caused whole-page horizontal overflow; history, intake and details then matched the phone viewport width. Wide record tables scroll within their container.

## Limits and pending work

- No live connectivity or API-account model access was verified. The public model identifier and Structured Outputs support were checked against official OpenAI documentation; see [versions](versions.md).
- The provisional prompt requests Needs review until approved clinical criteria are supplied. Mocked classification tests validate storage and the response contract, not clinical decisions.
- Method B contains only a small interface and an explicit unimplemented result. Reviewed rules, rule traces and a clinical validation process remain future work.
- No final dataset was imported, split, evaluated or used for performance claims. The planned 150 canonical cases and their variants remain outside setup.
- Narrative exclusions require researcher review. Quotation validation does not establish clinical accuracy, prevent every diagnosis in generated prose, or prove resistance to prompt injection.
- Processing is synchronous. A process interruption may leave a record marked Processing; automatic recovery is not implemented.
- The manuscript was not edited and the application was not deployed.
