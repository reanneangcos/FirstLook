# Connect the chatbot to OpenAI

This guide is for the local fictional-case thesis demo. The integration is already implemented in Laravel; you do not need to install another SDK or put an API key in React.

## 1. Understand when the API is used

The patient side at **http://localhost:8000/** asks the 15 study questions through a guided chat. Those intake questions are defined in application code and work without an API key. Each conversation receives a stub such as `TF-000001`.

When the patient finishes the questions, confirms the study boundaries and selects **Submit for screening**, Laravel sends the allowed patient answers to OpenAI. It saves the original response and displays the preliminary result in the chat. Staff see the conversation and full triage record at **http://localhost:8000/admin/patients**.

The API does not generate each intake question. This keeps the collected fields consistent across fictional cases. Stub numbers, staff identities and answer-key metadata never enter the patient prompt.

## 2. Create an API key

1. Sign in to the [OpenAI API platform](https://platform.openai.com/).
2. Select or create the project you want to use for this prototype.
3. Check that API billing is enabled for that project/account and that its usage allowance is sufficient. Set a budget appropriate for a small thesis demo.
4. Open the project's **API keys** page and create a secret key. Copy it when it is shown and keep it private.

The [official quickstart](https://developers.openai.com/api/docs/quickstart) explains API-key creation, and [production guidance](https://developers.openai.com/api/docs/guides/production-best-practices) covers project keys, billing and usage controls. You do not need to share the key in chat or commit it to Git.

## 3. Enter the key in this project

Open `.env` in the root of `THESIS2`, next to `compose.yaml`. Keep its existing `APP_KEY` and database settings. Edit these two lines:

```dotenv
OPENAI_API_KEY=your_actual_secret_key
OPENAI_MODEL=gpt-6-luna
```

Replace only `your_actual_secret_key` with the secret you copied. Do not add quotes copied from a formatted document, extra spaces within the key, or a `VITE_` prefix. `.env` is ignored by Git; `.env.example` must keep blank placeholders.

The official model identifier is [`gpt-6-luna`](https://developers.openai.com/api/docs/models/gpt-6-luna). Its public documentation lists Responses API and Structured Outputs support. Availability for your particular project still depends on account access. Do not silently switch the thesis model if access is rejected.

If this is a fresh checkout without `.env`, copy `.env.example` first and follow the [README setup](../README.md#start-with-docker), including application-key generation, dependency installation, migrations and account creation.

## 4. Reload Laravel's configuration

Open a terminal in `THESIS2` and run:

```sh
docker compose up -d app node
docker compose exec app php artisan config:clear
docker compose restart app
```

For native PHP development, run `php artisan config:clear` and restart `php artisan serve` instead. Editing `.env` does not require rebuilding React or Docker images.

In the staff dashboard, the integration notice should say **API configuration present**. This checks whether both settings are filled in; it does not prove the key or model access works.

## 5. Try one small fictional conversation

1. Open **http://localhost:8000/**. Confirm the fictional adult scope and start the chat.
2. Note the assigned stub. For a small test, use age `34`, main complaint `Itchy arm`, and description `Fictional example: my arm feels itchy since yesterday.` Choose **Unknown / skip** where information is unavailable.
3. Finish the questions, review the conversation and confirm the excluded-data checkbox.
4. Select **Submit for screening** once. Wait for the saved result; do not repeatedly click or reload to request more predictions.
5. Open **http://localhost:8000/admin/patients** in another tab and sign in with your existing staff account. Search the stub, open its conversation, then select **Full triage record**.
6. Inspect the returned model, original response, request attempts and token usage. A completed result with an accepted attempt confirms connectivity for that request.

The provisional prompt currently asks for **Needs review**, with no priority, because approved clinical criteria are pending. This is an expected result of the current prompt. API connectivity is separate from clinical validation.

A submission can make up to three bounded provider attempts for connection failures, rate limits, server failures or malformed output. Any actual requests may incur API usage charges. This guide does not run them automatically.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| Intake questions work but final screening fails | The intake guide works offline from the provider. Check the saved technical record in the staff side. |
| `configuration_missing` | Set both OpenAI variables in `.env`, clear config and restart `app`. Start a new fictional case for a new request. Existing failed records remain unchanged. |
| `credentials_rejected` | Check the key, project permissions, billing and model access in the API dashboard. |
| `request_rejected` | Check the configured model and whether the recorded request settings are supported. No fallback model is used. |
| `rate_limited` | Check project usage/limits and wait before trying another small case. The current retry sequence has already ended. |
| `connection_timeout` / `provider_unavailable` | Check internet access and provider availability. Staff can inspect attempt timestamps and failure codes. |
| Malformed or incomplete output | Inspect the retained attempt bodies and schema. Do not manually replace the recorded priority. |
| The chatbot stays on Processing | A server interruption may have prevented completion. Staff should inspect the saved record; automatic recovery is not implemented. |
| The browser no longer shows the old stub | The browser session expired, was cleared, or was ended. Staff can still find the record by stub. Stubs are not login codes. |

## Where to change things

| File | Purpose |
| --- | --- |
| `.env` | Your private API key and configured model |
| `config/triage.php` | Prompt version, request limits, model settings and study languages |
| `resources/prompts/screening-v0.1-provisional.txt` | Versioned screening prompt sent to OpenAI |
| `app/Services/OpenAI/OpenAIClient.php` | Server-side Responses API request |
| `app/Services/Screening/PatientInterview.php` | Guided chat questions, without changing the study field allowlist |
| `app/Services/Screening/PatientChatService.php` | Chat progression, stub assignment and linking the saved screening |
| `app/Services/Screening/ScreeningService.php` | Original response storage, bounded retries and accepted output |
| `app/Services/TriageRules/` | Future approved Method B rules; currently unimplemented |

Keep future prompt versions separate so existing records retain their original prompt. Both methods must use the same saved model response. Adding an API key does not implement Method B or approve clinical criteria.
