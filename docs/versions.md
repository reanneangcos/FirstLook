# Installed versions

Recorded October 3, 2026 (Asia/Manila). `composer.lock` and `package-lock.json` pin the resolved dependency tree. Use `composer install` and `npm ci`, rather than update commands, to reproduce it.

## Runtime and development environment

| Component | Verified version |
| --- | --- |
| PHP, host and Docker | 8.5.8 |
| Composer, Docker | 2.10.3 |
| Composer, existing host installation | 2.10.2 |
| Node.js, host and Docker | 24.14.1 |
| npm, host and Docker | 11.11.0 |
| Docker engine | 29.6.2 |
| Docker Compose | 5.3.1 |
| SQLite, Docker | 3.40.1 |
| SQLite, host | 3.53.3 |

The manuscript specifies Composer 2.10.2. The existing host installation was used for initial dependency resolution; its diagnostic check reported advisory CVE-2026-84361 / GHSA-rvx4-ffvw-m9q3. The Dockerfile therefore uses the 2.10.3 patch release. The global host installation was not changed. Other thesis runtime/framework versions were followed where specified.

SQLite was chosen because the manuscript does not specify a database. Native development uses `database/database.sqlite`; Docker uses a separate persistent `database_data` volume. They are separate databases with separate researcher accounts. Tests force an in-memory database.

## PHP dependencies

| Direct dependency | Installed version |
| --- | --- |
| laravel/framework | 13.30.1 |
| inertiajs/inertia-laravel | 2.0.25 |
| laravel/tinker | 3.0.2 |
| laravel/boost | 2.10.1 |
| laravel/pail | 1.2.7 |
| laravel/pao | 1.1.5 |
| laravel/pint | 1.32.1 |
| phpunit/phpunit | 12.5.37 |
| fakerphp/faker | 1.24.1 |
| mockery/mockery | 1.6.15 |
| nunomaduro/collision | 8.9.5 |

## JavaScript dependencies

| Direct dependency | Installed version |
| --- | --- |
| react / react-dom | 19.2.8 |
| @inertiajs/react | 2.3.27 |
| typescript | 5.9.3 |
| tailwindcss / @tailwindcss/vite | 4.3.3 |
| vite | 8.2.2 |
| @vitejs/plugin-react | 6.1.1 |
| laravel-vite-plugin | 3.2.0 |
| lucide-react | 0.468.0 |
| prettier | 3.9.5 |
| @types/node | 24.13.3 |
| @types/react | 19.2.18 |
| @types/react-dom | 19.2.5 |

## Model and request configuration

The publicly documented identifier is [`gpt-6-luna`](https://developers.openai.com/api/docs/models/gpt-6-luna). It is configurable through `OPENAI_MODEL`; `.env.example` leaves both model and API key blank. Public documentation does not establish access for a particular API account. No live provider request was made during verification.

The integration uses Responses API Structured Outputs, reasoning effort `low`, a 4,000-token output limit and `store: false`. Temperature/top-p are omitted and recorded as model defaults. No dated model snapshot has been pinned. See [architecture](architecture.md#openai-integration) for the study implications and retry settings.
