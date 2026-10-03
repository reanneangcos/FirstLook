# TriageFlow

A local research prototype for a paired comparison of multilingual preliminary screening methods. It uses fictional adult cases only. It does not diagnose, recommend treatment, train a model, or manage patient queues.

- **Method A:** the original accepted LLM response, preserved with its input and request metadata.
- **Method B:** **Not implemented**. Approved rules will consume that same saved response without another LLM call.
- **Priorities:** provisional ESI 1–5. **Needs review** has no priority. Technical failures are recorded separately.

The current prompt is provisional and requests **Needs review** until approved clinical criteria are supplied. Tests include explicitly mocked ESI outputs to verify the complete response contract. These fixtures do not establish clinical validity.

## What is included

Researcher sign-in, a dashboard with stored counts, structured intake, searchable history, and detailed session records with extracted facts, Method A output, original response, request settings, token usage and attempt history. No dataset or default user is seeded.

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

Open **http://localhost:8000** and sign in with the researcher account you created. Vite runs on port 5173 for frontend updates. Both published ports are bound to your computer's loopback interface.

To stop and restart later:

```sh
docker compose down
docker compose up -d app node
```

Normal stop/start preserves the database. `docker compose down -v` deletes named volumes, including the database; do not use it to restart the application.

## Use the prototype

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

Tests block unexpected HTTP requests and use an isolated in-memory SQLite database, forced in `phpunit.xml` even when Docker supplies a database path. Test fixtures are in `tests/Support/MockScreening.php`. They are not imported into the normal application database.

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
| `app/Http/Controllers/` | Short page, submission and sign-in controllers |
| `app/Http/Requests/StoreScreeningRequest.php` | Input validation and adult/synthetic scope confirmation |
| `app/Models/` and `database/migrations/` | Sessions, attempts and database schema |
| `app/Services/Screening/` | Allowlist, orchestration and strict output validation |
| `app/Services/OpenAI/OpenAIClient.php` | Server-only Responses API call |
| `app/Services/TriageRules/` | Small interface for future approved rules |
| `resources/prompts/` | Versioned provisional prompt |
| `resources/js/Pages/` | Dashboard, intake, history, details and authentication |
| `resources/js/Components/` and `types/` | Shared interface elements and types |
| `resources/css/` | Tailwind entry point and styles grouped by screen |
| `config/triage.php` | Model, prompt version, schema version, request limits |
| `docs/architecture.md` | Request flow, study boundaries and extension points |

## Edit the prompt or add approved rules

Create a new versioned prompt file in `resources/prompts/` and update `prompt_version` in `config/triage.php`. Existing sessions retain the prompt text and settings that created them. The current output schema is in `ScreeningOutput.php`.

Approved future rules belong behind `RuleLayer` in `app/Services/TriageRules/`. `PendingRuleLayer` returns `not_implemented` and no priority. It does not copy Method A. Future work must persist a separate rule version, supporting facts and trace while keeping Method A unchanged. Supply the same reviewed criteria to both methods before evaluation.

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

See [docs/verification.md](docs/verification.md) for executed checks and their limits. API transport was tested with mocks; no paid batch or live API request was made. Clinical criteria, clinical validation, reviewed multilingual cases, Method B rules and final evaluation remain pending. No thesis dataset was imported, manuscript edited or application deployed.
