# Optional LLM Setup & Safety Architecture (T13)

Last Year Project works 100% deterministically with `LLM_DRIVER=none`. Turning on an LLM only adds an optional **"AI-generated hypothesis - not proof"** summary on individual Action Center findings. It never calculates money, KPIs, or stats, never sees raw customer phone numbers/emails or API secrets, and never triggers store or courier actions.

---

## 1. Offline Local Setup with Ollama (Zero Cost, Offline Capable)

### Recommended Models & RAM Sizing (Windows / Linux)
Choose a model based on available free system RAM (or GPU VRAM):

| Hardware Tier | Suggested Ollama Model | Approximate Download / Runtime RAM | Expected Quality Notes |
| --- | --- | --- | --- |
| 8 GB RAM CPU-only | `llama3.2:3b` (Q4) or `qwen2.5:3b` | ~2.0 GB disk / ~3.5–4.5 GB RAM | Fast on CPU; good for short English bullet summaries. Bangla phrasing is weak — cross-check deterministic Bangla templates. |
| 16 GB RAM CPU / 6–8 GB VRAM | `llama3.1:8b` or `qwen2.5:7b` | ~4.7 GB disk / ~6–8 GB RAM | Solid English reasoning and clearer structured summaries; still treat Bangla output as secondary to deterministic templates. |
| 24–32 GB RAM / 12+ GB VRAM | `qwen2.5:14b` | ~9.0 GB disk / ~11–14 GB RAM | Higher factual fidelity on noisy tables; slower on CPU-only laptops. |

> **Bangla Quality Caveat:** Small offline 3B–8B quantized models frequently mix dialects or drop Bangla inflections. Always treat deterministic Action Center rules (which have handwritten English and Bangla templates) as the authoritative record.

### Step-by-Step Local Setup
1. Install Ollama locally and pull one model:
   ```bash
   ollama pull llama3.2:3b
   ```
2. Ensure Ollama is listening on `http://127.0.0.1:11434` (`ollama serve`).
3. Set `.env`:
   ```env
   LLM_DRIVER=ollama
   OLLAMA_BASE_URL=http://127.0.0.1:11434
   OLLAMA_MODEL=llama3.2:3b
   LLM_MONTHLY_TOKEN_BUDGET=100000
   LLM_MAX_INPUT_CHARS=12000
   LLM_MAX_TOKENS=600
   LLM_TIMEOUT=60
   ```
4. Test connectivity from CLI or System page:
   ```bash
   php artisan llm:ping
   ```
5. Run the dedicated AI queue worker (180s timeout so slow CPU models do not block web requests or other jobs):
   ```bash
   php artisan queue:work --queue=ai --timeout=180
   ```
   (Or run all queues together in single-worker dev: `php artisan queue:work --queue=high,default,ai --timeout=180`.)

---

## 2. Optional Cloud Providers (.env Keys Only)

**Never commit real API keys to git, database, or client-side JavaScript.** Only put provider keys inside local `.env`:

### OpenAI / OpenAI-Compatible Endpoints
```env
LLM_DRIVER=openai
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
```
- Verified API contract: `POST {OPENAI_BASE_URL}/chat/completions` with `Authorization: Bearer <key>`, JSON body `{model, messages, max_tokens}`, parsing `choices[0].message.content` and `usage.{prompt_tokens, completion_tokens}`. `ping()` calls `GET {OPENAI_BASE_URL}/models`.
- Vendor reference: OpenAI Chat Completions API (`https://platform.openai.com/docs/api-reference/chat`).

### Anthropic (Claude Messages API)
```env
LLM_DRIVER=anthropic
ANTHROPIC_BASE_URL=https://api.anthropic.com
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_VERSION=2023-06-01
ANTHROPIC_MODEL=claude-3-5-haiku-latest
```
- Verified API contract: `POST {ANTHROPIC_BASE_URL}/v1/messages` with headers `x-api-key` and `anthropic-version: 2023-06-01`, JSON body `{model, max_tokens, system, messages}`, parsing `content[].text` and `usage.{input_tokens, output_tokens}`. `ping()` calls `GET {ANTHROPIC_BASE_URL}/v1/models`.
- Vendor reference: Anthropic Messages API (`https://docs.anthropic.com/en/api/messages`) and Models API (`https://docs.anthropic.com/en/api/models`).

### Ollama (Local REST API)
- Verified API contract: `POST {OLLAMA_BASE_URL}/api/chat` with `{model, messages, stream: false, options: {num_predict}}`, parsing `message.content` and `{prompt_eval_count, eval_count}`. `ping()` calls `GET {OLLAMA_BASE_URL}/api/tags`.
- Vendor reference: Ollama REST API (`https://github.com/ollama/ollama/blob/main/docs/api.md`).

---

## 3. Guardrails Enforced in Code (`App\Services\Llm`)

- **Strict Input Fencing (`Policy::untrusted`, `Policy::data`):** Store names, product/courier strings, and evidence text have tag delimiters normalized (`<` -> `[`, `>` -> `]`) and wrapped inside `<untrusted_data>...</untrusted_data>` before serializing single-finding evidence inside `<evidence_json>...</evidence_json>`. Sensitive keys (`secret`, `token`, `password`, `api_key`, `phone`, `email`, `customer_key`) are stripped.
- **Size & Monthly Budget Caps Before HTTP Calls (`LlmClient::complete`):** Oversized inputs (`> max_input_chars`) and exhausted per-tenant monthly token budgets (`SUM(input_tokens + output_tokens) >= monthly_token_budget`) are logged with 0 tokens and rejected BEFORE any outbound HTTP request is sent.
- **Privacy Ledger (`llm_calls`):** Only tenant ID, purpose, driver, model, token counts, latency, status, and sanitized error messages are stored — **zero prompts and zero LLM answers are stored in `llm_calls`**.
- **Read-Only Asynchronous Execution (`RunLlmExplanation`):** Runs on queue `ai` with 180s timeout; writes only `ai_note`, `ai_model`, `ai_generated_at`, `ai_error` on that single finding without ever changing finding metrics, severity, confidence, money at stake, or status.
