# T13: Optional LLM explanation

**Depends on:** T12

## Goal
A clearly labelled AI explanation per finding that can be switched off without losing anything.

## Read first
AGENT_RULES.md, docs/RND_LAB_SPEC.md (Optional LLM), docs/SECURITY_RULES.md (AI), docs/ARCHITECTURE.md

## Deliverables
- config/llm.php; App\Services\Llm\LlmClient (drivers ollama / openai-compatible / anthropic), Policy (system prompt, untrusted() fence, data() wrapper), LlmUnavailable and LlmBudgetExceeded, llm_calls ledger WITHOUT prompts/answers, per-tenant monthly token budget, input size cap, timeouts.
- RunLlmExplanation job on queue `ai`; stores ai_note + ai_model on the finding; UI button 'AI explanation (optional)' with label 'AI-generated hypothesis - not proof'.
- System page: driver, model, tokens used / budget, Test connection button; `php artisan llm:ping`.
- Docs: Ollama offline setup (model sizes/RAM), cloud keys in .env only. Verify every driver's request/response format against the vendor's official documentation before coding and cite the pages in the report.

## Done when (acceptance criteria)
- [ ] With LLM_DRIVER=none every page works and the button explains how to enable AI.
- [ ] The model never receives secrets or other tenants' data and never changes a finding's numbers or status.

## Required tests
- Http::fake tests per driver; budget exceeded; oversized input rejected before any HTTP call; ledger has no prompt/answer text; untrusted text cannot break out of the fence; HTTP 500 handled gracefully; per-tenant budget isolation; job runs on the `ai` queue.

## Do NOT
- ['No tool-calling, no autonomous actions, no chat UI here.']
- Do not touch anything outside this task. Do not weaken or delete existing tests.

## Owner verification (the owner runs these on the XAMPP PC)
- ['With Ollama running, click the button on a finding; then stop Ollama and confirm the page still works.']
- `php artisan test` is green.

## Finish
Update docs/PROGRESS.md, commit, and send the final report in the AGENT_RULES.md format.
