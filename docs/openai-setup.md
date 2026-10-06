# Connect the chatbot to OpenAI

This guide is for the local fictional-case thesis demo. The integration is already implemented in Laravel; you do not need to install another SDK or put an API key in React.

## 1. Understand when the API is used

The patient side at **http://localhost:8000/** collects the 15 patient fields from columns B:P of each TRAIN tab through a conversational chat. Questions, follow-ups, controls and patient messages use the selected English, Bisaya or Tagalog language. Each conversation receives a stub such as `TF-000001`.

When the patient finishes the questions, confirms the study boundaries and selects **Submit for screening**, Laravel sends the allowed patient answers to OpenAI. It saves the original response and displays the preliminary result in the chat. Staff see the conversation and full triage record at **http://localhost:8000/admin/patients**.

Each typed intake reply now makes one OpenAI request. Luna extracts any of the 15 fields mentioned in that reply and proposes a brief question in the selected language. Laravel chooses which fields are still needed, groups related details, and limits each field to an initial question plus one clarification. Starting and skipping work without an API call. A failed request keeps the current question and draft; it does not silently discard the reply or mark the information absent. Old conversations without conversational state retain their previous guided flow.

The final patient screening uses a versioned prompt that requests the explanation in the selected language. Extracted evidence keeps its original wording, including mixed-language replies. Stub numbers, staff identities and answer-key metadata stay out of the prompt. The separate structured research intake retains its existing prompt.

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
3. Finish the missing details. Expand **Review or correct your details** to fix anything captured incorrectly, then confirm the excluded-data checkbox.
4. Select **Submit for screening** once. Wait for the saved result; do not repeatedly click or reload to request more predictions.
5. Open **http://localhost:8000/admin/patients** in another tab and sign in with your existing staff account. Search the stub, open its conversation, then select **Full triage record**.
6. Inspect the returned model, original response, request attempts and token usage. A completed result with an accepted attempt confirms connectivity for that request.

The active `screening-v0.3-llm-baseline` prompt allows **ESI 1–5** as an exploratory Luna-only estimate. Needs review remains appropriate when essential information is missing or contradictory. The model uses its pretrained understanding while reviewed study criteria are pending. Each record is marked `exploratory_llm_only`; these outputs are not the final criteria-controlled comparison described in the paper. Existing saved Needs-review records keep their original result. Start a new fictional case to try the new prompt.

Each typed reply makes one attempt with no automatic retry. The final screening submission can make up to three bounded provider attempts for connection failures, rate limits, server failures or malformed output. Any actual requests may incur API usage charges. This guide does not run them automatically.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| The bot cannot read a reply | Check the server’s OpenAI configuration and project usage. The draft stays in the composer; resend after resolving the problem, or skip those details. |
| Intake works but final screening fails | Intake and final screening are separate calls. Check the saved screening technical record in the staff side. |
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
| `resources/prompts/screening-v0.3-llm-baseline.txt` | Active exploratory classification prompt for patient and structured research submissions |
| `resources/prompts/screening-v0.1-provisional.txt` and `screening-v0.2-patient-language.txt` | Historical review-only prompts retained for traceability |
| `resources/chat/{en,ceb,fil}.json` | Shared translations for questions, follow-ups, messages and controls |
| `app/Services/Screening/PatientFields.php` | Allowed patient fields and their exact TRAIN column names |
| `app/Services/OpenAI/OpenAIClient.php` | Server-side Responses API request |
| `resources/prompts/intake-v1-conversational.txt` | Understanding-only extraction and natural question instructions |
| `app/Services/Screening/IntakeInterpreter.php` | Strict fact extraction schema, evidence checks and provider call |
| `app/Services/Screening/ConversationalInterview.php` | Missing-field groups, clarification limits and answer preservation |
| `resources/js/Components/PatientAnswerReview.tsx` | Review and correction panel |
| `app/Services/Screening/PatientInterview.php` and `PatientFollowUp.php` | Compatibility for older guided conversations |
| `app/Services/Screening/PatientChatService.php` | Chat progression, stub assignment and linking the saved screening |
| `app/Services/Screening/ScreeningService.php` | Original response storage, bounded retries and accepted output |
| `app/Services/TriageRules/` | Future approved Method B rules; currently unimplemented |

Keep future prompt versions separate so existing records retain their original prompt. Use the same case inputs for comparison. Method A lets Luna decide triage. Method B lets Luna understand information only; approved rules alone decide, without reading or falling back to the Method A priority. Adding an API key does not implement Method B or approve clinical criteria.
