# TriageFlow

A local research prototype for a paired comparison of multilingual preliminary screening methods. It uses fictional adult cases only. It does not diagnose, recommend treatment, train a model, or manage patient queues.

- **Method A — GPT Luna triage:** Luna understands the case and assigns the preliminary triage result. Its original response and request metadata are preserved.
- **Method B — hybrid:** the deterministic ESI v4 layer evaluates the same saved extracted facts and records a separate result and trace. Clinical criteria await reviewer approval, and the current patient-reported input lacks required clinical assessments. Method B returns Needs review when unsupported and never copies or falls back to Luna’s priority.
- **Priorities:** provisional ESI 1–5. **Needs review** has no priority. Technical failures are recorded separately.

The active `screening-v0.3-llm-baseline` prompt enables **exploratory Luna-only triage** for fictional adult cases. It can return ESI 1–5 using the model’s pretrained understanding, or Needs review when the reported information does not support an estimate. Reviewed study criteria are still pending: these runs are development demonstrations, not the paper’s final baseline evaluation. Older prompts and saved outcomes remain unchanged. Each new screening records its classification mode, prompt and criteria status.

## What is included

Two interfaces share one saved research record:

- **Patient side — `/`:** guided chatbot, automatic stub number, saved messages, same-browser resume and a preliminary screening response after submission.
- **Admin / healthcare staff — `/admin`:** protected dashboard, searchable patient stubs, complete conversations and linked triage records. The original structured research intake and screening history remain available here.

All accounts created through `researcher:create` have staff access. Patients do not need accounts and cannot open staff records. No dataset or default user is seeded. Existing accounts continue to work.

For a step-by-step API walkthrough, read **[Connect the chatbot to OpenAI](docs/openai-setup.md)**.

## Required software

**Docker setup:** Docker Desktop with Docker Compose, a terminal, and a browser. Start Docker Desktop first. You do not need PHP or Node installed on your computer for this path.

The `app` service runs Laravel/PHP. The `node` service runs Vite. SQLite is the database because the thesis does not specify one. Docker keeps it in a named `database_data` volume across ordinary restarts and `docker compose down`. The Compose project is named `triageflow-thesis2` to keep this checkout separate from other TriageFlow projects.

See [the installed version record](docs/versions.md) for exact versions and the small Composer patch difference from the manuscript.

## Start with Docker

Run all commands from the project folder. The first build downloads dependencies and can take several minutes.

### 1. Create the environment file

For a fresh checkout:

```sh
cp .env.example .env
```

If `.env` already exists, keep it. Open it in your editor. For a live request, fill in these **server-only** settings:

```dotenv
OPENAI_API_KEY=
OPENAI_MODEL=gpt-6-luna
```

Enter your own API key after the first equals sign. Never use a `VITE_` variable for credentials. `.env` is ignored by Git. Leaving either setting blank is supported: submission saves a technical-failure record and sends no API request.

Official OpenAI documentation identifies [GPT-6 Luna as `gpt-6-luna`](https://developers.openai.com/api/docs/models/gpt-6-luna) and lists Structured Outputs support. This does not verify access for your account. No model is silently substituted. See [integration notes](docs/architecture.md#openai-integration).

### 2. Build and install locked dependencies

```sh
docker compose build app
docker compose run --rm app composer install --no-interaction
docker compose run --rm node npm ci --ignore-scripts
```

### 3. Initialize the database and account

Run key generation only on the first setup, when `APP_KEY` is blank. It writes the key into `.env`.

```sh
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan researcher:create
```

The account command asks for a researcher name, email and a password of at least 12 characters. Password entry is hidden. There is no public registration or default password. All researchers in this local workspace can inspect its synthetic sessions.

### 4. Start the application

```sh
docker compose up -d app node
```

Open **http://localhost:8000/** for the patient chatbot, or **http://localhost:8000/admin** and sign in with your staff account. Vite runs on port 5173 for frontend updates. Both published ports are bound to your computer's loopback interface.

To stop and restart later:

```sh
docker compose down
docker compose up -d app node
```

Normal stop/start preserves the database. `docker compose down -v` deletes named volumes, including the database; do not use it to restart the application.

## Update an existing checkout

```sh
docker compose exec app php artisan migrate
docker compose restart app node
```

The new migrations add conversations and their links to screening records. They preserve existing staff accounts and screenings. Do not use `migrate:fresh` on a database you want to keep.

## Use the patient and staff sides

1. Open the patient chatbot at `/`, confirm a fictional adult case, and choose English, Bisaya or Tagalog. Questions, controls and patient messages follow that language. Submitted answers can use any language variant or mix and keep their original wording.
2. Start a conversation to receive a stub such as `TF-000001`. Describe the concern naturally: one reply can fill several of the 15 dataset fields. The bot skips captured details and groups related missing information. Vague answers get one targeted clarification. Skipping retains any partial answer; unavailable information stays unknown.
3. Expand **Review or correct your details**, check the captured answers, confirm the excluded-data boundaries and submit for screening. Each typed reply uses one server-side OpenAI call to understand the reply and write the next question. Starting and skipping use no API calls. Final screening is a separate request. Failed intake calls keep the question and draft available for retry.
4. Staff sign in at `/admin`, select **Patients & chats**, search the stub and open its complete history. **Full triage record** links to the original response and technical metadata.

Existing conversations started before the conversational update finish with their original guided flow. Start a new conversation to try the new questions.

The same browser resumes its current conversation while its session remains valid (120 minutes of inactivity by default). Refreshing does not allocate a new stub. Ending a conversation, clearing cookies, staff sign-out in the same browser, or session expiry removes patient access; staff retain the saved record. A stub is a record reference, not a login credential or queue position. For a shared demo device, end the current conversation before the next fictional patient.

## Use the structured research intake

1. Select **New screening** and enter one fictional adult case in its original language.
2. Leave unavailable fields blank. They are stored as unknown, never interpreted as negative findings. Exact submitted patient wording is retained separately.
3. Record completed/requested test **names only**. Exclude result values, measured vital signs, examination findings, identifiers and answer keys. Confirm the study boundaries before submission.
4. Inspect the saved response and technical record. The loading state can last about 95 seconds for three timed-out attempts. Do not resubmit just because the model's valid priority is unexpected.
5. Use history to search by complaint, internal session ID, case ID or variant ID, and filter by processing status.

The application has an explicit patient-field allowlist. It cannot reliably detect every prohibited fact pasted into narrative text. Researchers must check content before submission; the prompt also instructs the model to ignore excluded information and embedded instructions. Prompt-injection resistance and medical correctness have not been established by the mocked tests.

## Run checks

```sh
docker compose run --rm app php artisan test --compact
docker compose run --rm node npm run build
docker compose run --rm app vendor/bin/pint --dirty --format agent
```

Tests block unexpected HTTP requests and use an isolated in-memory SQLite database, forced in both PHPUnit environment and server variables, with a pre-migration guard in `tests/TestCase.php`. Test fixtures are in `tests/Support/MockScreening.php`. They are not imported into the normal application database.

The build includes TypeScript checking. `npm run format` formats frontend source. Running a production asset build while the Vite service is active may remove its `public/hot` marker; restart `node` to return to hot reloading.

## Native setup alternative

Requires PHP 8.5 with SQLite, mbstring, XML/DOM, curl and zip support; Composer; Node 24; and npm. Use the pinned versions in [docs/versions.md](docs/versions.md).

```sh
cp .env.example .env
composer install
npm ci --ignore-scripts
touch database/database.sqlite
php artisan key:generate
php artisan migrate
php artisan researcher:create
npm run build
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000. For hot reloading, run `npm run dev` in a second terminal. Keep an existing `.env` and application key. Native SQLite uses `database/database.sqlite`; it is separate from Docker's database volume.

## Important files

| Location | Purpose |
| --- | --- |
| `app/Http/Controllers/PatientChatController.php` | Patient session and chat endpoints |
| `app/Http/Controllers/PatientRecordController.php` | Staff list/search and transcript pages |
| `app/Http/Controllers/` | Screening and sign-in controllers |
| `app/Http/Requests/StoreScreeningRequest.php` | Input validation and adult/synthetic scope confirmation |
| `app/Models/` and `database/migrations/` | Sessions, attempts and database schema |
| `app/Services/Screening/` | Allowlist, orchestration and strict output validation |
| `app/Services/OpenAI/OpenAIClient.php` | Server-only Responses API call |
| `app/Services/TriageRules/` | Small interface for future approved rules |
| `resources/prompts/` | Versioned provisional prompt |
| `resources/chat/` | English, Bisaya and Tagalog question, follow-up and interface translations |
| `resources/js/Pages/Patient/` | Patient chatbot |
| `resources/js/Pages/Admin/Patients/` | Staff patient list and full conversation |
| `resources/js/Pages/Screenings/` | Research intake, screening history and technical details |
| `resources/js/Components/` and `types/` | Shared interface elements and types |
| `resources/css/` | Tailwind entry point and styles grouped by screen |
| `config/triage.php` | Model, prompt version, schema version, request limits |
| `docs/architecture.md` | Request flow, study boundaries and extension points |

## Edit the prompt or add approved rules

Create a new versioned prompt file in `resources/prompts/` and update `prompt_version` for the structured research intake or `patient_prompt_version` for the patient chatbot in `config/triage.php`. Existing sessions retain the prompt text and settings that created them. The final screening schema is in `ScreeningOutput.php`. Conversational extraction and question wording use `intake-v1-conversational.txt` and `IntakeInterpreter`; question groups and repeat limits live in `ConversationalInterview`. Changing intake also changes the study input collection process, so freeze it before evaluation.

`RuleLayer` is bound to `EsiV4RuleLayer` in `app/Services/TriageRules/`. New screenings pass the same accepted LLM-extracted facts to Method B and save a separate result, input snapshot, rule version and audit trace. Method A's response and priority remain unchanged. The engine makes no provider request and does not accept Method A's priority as a decision or fallback. Historical records remain unevaluated by this layer.

The engine follows the [official ESI v4 A–D order](https://www.ahrq.gov/sites/default/files/publications2/files/esitriagealgorithm-v4_0.pdf). The [AHRQ v4 handbook](https://www.govinfo.gov/content/pkg/GOVPUB-HE20_6500-PURL-gpo23161/pdf/GOVPUB-HE20_6500-PURL-gpo23161.pdf) supplies the interpretation of resource estimates, acute mental-status changes, pain assessment and measured vital signs. No MTS criteria are used. Danger-zone adult vitals flag clinical judgment without assigning ESI 2. Pain scores alone do not assign ESI 2.

Clinical approvals belong in `config/esi.php`, keyed by the IDs in `EsiV4RuleCatalog`. Each approval requires `status`, `reviewer`, `reviewed_at` (YYYY-MM-DD), `record_id` and the matching `rule_version`. No approvals have been supplied, so all clinical rules remain pending.

**The study deliberately excludes vital signs and professional assessment findings. Collecting them is not a remaining implementation task.** Manuscript sections 1.5, 3.5 and 3.11 require preliminary ESI-based decisions supported by permitted patient-reported facts. Clinical review must define which rules and levels those facts can support; excluded information must not be guessed or replaced with invented symptom thresholds. Standard ESI v4 Decision D cannot be completed within this input scope, and skipping it does not establish ESI 3.

The current engine implements the standard decision order, but its optional clinical-assessment argument is outside the study input contract and is not connected to patient endpoints. The mappings from permitted reports to reviewed screening criteria are still unimplemented. Consequently, current Method B outputs require review, often at A before reaching D. Final study development needs a reviewed rule specification for the allowed inputs, followed by implementation and validation of that specification. Both methods must receive the same permitted case information and reviewed criteria for the final comparison. This is not yet a complete implementation of the paper's classification procedure.

## Troubleshooting

| Symptom | What to check |
| --- | --- |
| Docker cannot connect | Open Docker Desktop and wait until its engine is running. |
| Build/download timeout | Retry the same install command; completed downloads are cached. Check your network. |
| Port 8000 or 5173 is occupied | Stop the other local development server, or update `compose.yaml` and the Vite HMR port together. |
| `vendor/autoload.php` missing | Run the Docker Composer install command above. |
| Missing application key | Run `key:generate` once; keep the resulting `.env`. |
| Missing database/table | Run `docker compose run --rm app php artisan migrate`. |
| Sign-in fails | Create an account in the environment you are using. Native and Docker databases are separate. |
| Blank page or stale assets | Check `docker compose logs --tail=50 node`; restart `node`. For built assets, run the build command. |
| Session expired | Sign in again. Check history before repeating a submission. |
| Configuration missing | Fill both OpenAI variables, run `docker compose exec app php artisan config:clear`, then `docker compose restart app`. |
| Credentials rejected | Check your key, project access and `gpt-6-luna` access. No fallback model is used. |
| Provider rate limit/timeout | The saved record shows each bounded attempt. Wait and investigate; no automatic infinite retry occurs. |
| Interrupted request remains Processing | Inspect its attempts. This synchronous prototype has no background recovery job and never assigns a fallback priority. |

## Verification and pending work

See [docs/verification.md](docs/verification.md) for previous executed checks and their limits. Automated tests use mocked API responses and in-memory SQLite. Small fictional browser checks use the configured live API; no dataset batch was run. Clinical approval of criteria, clinical validation, reviewed multilingual cases and final evaluation remain pending. Rule tests use explicitly fictional assessment and approval fixtures to check software behavior. No thesis dataset was imported, manuscript edited or application deployed.
