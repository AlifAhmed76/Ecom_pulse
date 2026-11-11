<?php

namespace App\Services\Llm;

use App\Models\ResearchFinding;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class LlmClient
{
    public const SUPPORTED_DRIVERS = ['none', 'ollama', 'openai', 'anthropic'];

    public function driver(): string
    {
        $driver = strtolower(trim((string) config('llm.driver', 'none')));

        return in_array($driver, self::SUPPORTED_DRIVERS, true) ? $driver : 'none';
    }

    public function model(): string
    {
        $driver = $this->driver();
        if ($driver === 'none') {
            return '';
        }

        $override = trim((string) (config('llm.model') ?? ''));
        if ($override !== '') {
            return $override;
        }

        return trim((string) config("llm.drivers.{$driver}.model", ''));
    }

    public function isEnabled(): bool
    {
        return $this->driver() !== 'none';
    }

    /**
     * Explicit tenant-scoped monthly token ledger summary (never leaks across tenants, works cleanly in unauthenticated jobs).
     *
     * @return array{input_tokens: int, output_tokens: int, total_tokens: int, budget: int, remaining: int, calls_count: int}
     */
    public function tenantUsageThisMonth(int $tenantId): array
    {
        $budget = max(0, (int) config('llm.monthly_token_budget', 100000));
        $monthStart = now()->startOfMonth()->format('Y-m-d H:i:s');

        $row = DB::table('llm_calls')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ok')
            ->where('created_at', '>=', $monthStart)
            ->selectRaw('COALESCE(SUM(input_tokens), 0) AS in_tok, COALESCE(SUM(output_tokens), 0) AS out_tok, COUNT(*) AS cnt')
            ->first();

        $in = (int) ($row->in_tok ?? 0);
        $out = (int) ($row->out_tok ?? 0);
        $total = $in + $out;

        return [
            'input_tokens' => $in,
            'output_tokens' => $out,
            'total_tokens' => $total,
            'budget' => $budget,
            'remaining' => max(0, $budget - $total),
            'calls_count' => (int) ($row->cnt ?? 0),
        ];
    }

    /**
     * Generate an advisory explanation for a single ResearchFinding without modifying its metrics or status.
     *
     * @return array{note: string, model: string, input_tokens: int, output_tokens: int, latency_ms: int}
     */
    public function explainFinding(ResearchFinding $finding, string $locale = 'en'): array
    {
        $messages = Policy::buildFindingMessages($finding, $locale);
        $result = $this->complete(
            (int) $finding->tenant_id,
            'finding_explain',
            $messages['system'],
            $messages['user']
        );

        return [
            'note' => $result['content'],
            'model' => $result['model'],
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
            'latency_ms' => $result['latency_ms'],
        ];
    }

    /**
     * Send a bounded completion request via the configured LLM driver.
     *
     * @return array{content: string, driver: string, model: string, input_tokens: int, output_tokens: int, latency_ms: int}
     */
    public function complete(
        int $tenantId,
        string $purpose,
        string $systemPrompt,
        string $userPrompt
    ): array {
        $driver = $this->driver();
        if ($driver === 'none') {
            throw new LlmUnavailable('LLM is disabled (LLM_DRIVER=none).');
        }

        $model = $this->model();
        if ($model === '') {
            throw new LlmUnavailable("No model configured for LLM driver '{$driver}'.");
        }

        // Verify cloud API keys BEFORE size/budget/network checks when cloud driver selected
        if (in_array($driver, ['openai', 'anthropic'], true)) {
            $apiKey = trim((string) config("llm.drivers.{$driver}.api_key", ''));
            if ($apiKey === '') {
                throw new LlmUnavailable("Missing API key for '{$driver}' driver in environment configuration.");
            }
        }

        // 1) Input size cap BEFORE any HTTP request
        $maxChars = max(256, (int) config('llm.max_input_chars', 12000));
        $totalChars = mb_strlen($systemPrompt, 'UTF-8') + mb_strlen($userPrompt, 'UTF-8');
        if ($totalChars > $maxChars) {
            $this->recordCall($tenantId, $purpose, $driver, $model, 0, 0, 0, 'rejected_oversized', "Input {$totalChars} chars exceeds cap ({$maxChars}).");
            throw new InvalidArgumentException("LLM input exceeds maximum cap of {$maxChars} characters.");
        }

        // 2) Per-tenant monthly token budget BEFORE any HTTP request
        $usage = $this->tenantUsageThisMonth($tenantId);
        if ($usage['total_tokens'] >= $usage['budget']) {
            $this->recordCall($tenantId, $purpose, $driver, $model, 0, 0, 0, 'budget_exceeded', "Monthly token budget ({$usage['budget']}) reached ({$usage['total_tokens']} used).");
            throw new LlmBudgetExceeded("Monthly AI token budget ({$usage['budget']}) exhausted for this workspace.");
        }

        $started = hrtime(true);
        try {
            $parsed = match ($driver) {
                'ollama' => $this->callOllama($model, $systemPrompt, $userPrompt),
                'openai' => $this->callOpenAi($model, $systemPrompt, $userPrompt),
                'anthropic' => $this->callAnthropic($model, $systemPrompt, $userPrompt),
                default => throw new LlmUnavailable("Unsupported LLM driver '{$driver}'."),
            };
        } catch (LlmUnavailable $e) {
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $this->recordCall($tenantId, $purpose, $driver, $model, 0, 0, $latencyMs, 'error', $this->sanitizeError($e->getMessage()));
            throw $e;
        } catch (ConnectionException $e) {
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $safe = $this->sanitizeError("Connection failed for '{$driver}': ".$e->getMessage());
            $this->recordCall($tenantId, $purpose, $driver, $model, 0, 0, $latencyMs, 'error', $safe);
            throw new LlmUnavailable($safe, 0, $e);
        } catch (Throwable $e) {
            $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
            $safe = $this->sanitizeError("LLM provider error: ".$e->getMessage());
            $this->recordCall($tenantId, $purpose, $driver, $model, 0, 0, $latencyMs, 'error', $safe);
            throw new LlmUnavailable($safe, 0, $e);
        }

        $latencyMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $content = trim($parsed['content']);
        if ($content === '') {
            $this->recordCall($tenantId, $purpose, $driver, $model, $parsed['input_tokens'], $parsed['output_tokens'], $latencyMs, 'error', 'Empty response content from provider.');
            throw new LlmUnavailable('Provider returned empty explanation text.');
        }

        $this->recordCall($tenantId, $purpose, $driver, $model, $parsed['input_tokens'], $parsed['output_tokens'], $latencyMs, 'ok', null);

        return [
            'content' => $content,
            'driver' => $driver,
            'model' => $model,
            'input_tokens' => $parsed['input_tokens'],
            'output_tokens' => $parsed['output_tokens'],
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Lightweight connectivity probe for System page & `php artisan llm:ping`.
     *
     * @return array{ok: bool, driver: string, model: string, latency_ms: int, message: string}
     */
    public function ping(): array
    {
        $driver = $this->driver();
        $model = $this->model();

        if ($driver === 'none') {
            return [
                'ok' => false,
                'driver' => 'none',
                'model' => '',
                'latency_ms' => 0,
                'message' => 'LLM is disabled (LLM_DRIVER=none). Set LLM_DRIVER=ollama|openai|anthropic in .env to enable.',
            ];
        }

        $timeout = min(15, max(3, (int) config('llm.timeout', 60)));
        $started = hrtime(true);

        try {
            if ($driver === 'ollama') {
                $base = rtrim((string) config('llm.drivers.ollama.base_url', 'http://127.0.0.1:11434'), '/');
                $resp = Http::timeout($timeout)->acceptJson()->get("{$base}/api/tags");
                $ms = (int) round((hrtime(true) - $started) / 1_000_000);

                if ($resp->failed()) {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'model' => $model,
                        'latency_ms' => $ms,
                        'message' => "Ollama returned HTTP {$resp->status()} on /api/tags.",
                    ];
                }

                $names = [];
                foreach ((array) $resp->json('models', []) as $m) {
                    if (is_array($m) && isset($m['name'])) {
                        $names[] = (string) $m['name'];
                    }
                }
                $hasModel = $model !== '' && (in_array($model, $names, true) || in_array($model.':latest', $names, true));

                return [
                    'ok' => true,
                    'driver' => $driver,
                    'model' => $model,
                    'latency_ms' => $ms,
                    'message' => $hasModel
                        ? "Connected to Ollama at {$base}; configured model '{$model}' is installed."
                        : "Connected to Ollama at {$base}, but model '{$model}' was not found in local tags (run: ollama pull {$model}).",
                ];
            }

            if ($driver === 'openai') {
                $apiKey = trim((string) config('llm.drivers.openai.api_key', ''));
                if ($apiKey === '') {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'model' => $model,
                        'latency_ms' => 0,
                        'message' => 'OPENAI_API_KEY is missing in .env.',
                    ];
                }

                $base = rtrim((string) config('llm.drivers.openai.base_url', 'https://api.openai.com/v1'), '/');
                $resp = Http::timeout($timeout)->withToken($apiKey)->acceptJson()->get("{$base}/models");
                $ms = (int) round((hrtime(true) - $started) / 1_000_000);

                if ($resp->failed()) {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'model' => $model,
                        'latency_ms' => $ms,
                        'message' => "OpenAI-compatible endpoint returned HTTP {$resp->status()} on /models.",
                    ];
                }

                return [
                    'ok' => true,
                    'driver' => $driver,
                    'model' => $model,
                    'latency_ms' => $ms,
                    'message' => "Connected to OpenAI-compatible API ({$base}) in {$ms} ms.",
                ];
            }

            if ($driver === 'anthropic') {
                $apiKey = trim((string) config('llm.drivers.anthropic.api_key', ''));
                if ($apiKey === '') {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'model' => $model,
                        'latency_ms' => 0,
                        'message' => 'ANTHROPIC_API_KEY is missing in .env.',
                    ];
                }

                $base = rtrim((string) config('llm.drivers.anthropic.base_url', 'https://api.anthropic.com'), '/');
                $version = (string) config('llm.drivers.anthropic.version', '2023-06-01');
                $resp = Http::timeout($timeout)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => $version,
                    ])
                    ->acceptJson()
                    ->get("{$base}/v1/models");
                $ms = (int) round((hrtime(true) - $started) / 1_000_000);

                if ($resp->failed()) {
                    return [
                        'ok' => false,
                        'driver' => $driver,
                        'model' => $model,
                        'latency_ms' => $ms,
                        'message' => "Anthropic returned HTTP {$resp->status()} on /v1/models.",
                    ];
                }

                return [
                    'ok' => true,
                    'driver' => $driver,
                    'model' => $model,
                    'latency_ms' => $ms,
                    'message' => "Connected to Anthropic API ({$base}) in {$ms} ms.",
                ];
            }
        } catch (Throwable $e) {
            $ms = (int) round((hrtime(true) - $started) / 1_000_000);

            return [
                'ok' => false,
                'driver' => $driver,
                'model' => $model,
                'latency_ms' => $ms,
                'message' => $this->sanitizeError($e->getMessage()),
            ];
        }

        return [
            'ok' => false,
            'driver' => $driver,
            'model' => $model,
            'latency_ms' => 0,
            'message' => "Unsupported driver '{$driver}'.",
        ];
    }

    /**
     * Verified against Ollama docs (`POST /api/chat`).
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     */
    private function callOllama(string $model, string $systemPrompt, string $userPrompt): array
    {
        $base = rtrim((string) config('llm.drivers.ollama.base_url', 'http://127.0.0.1:11434'), '/');
        $timeout = max(5, (int) config('llm.timeout', 60));
        $maxTokens = max(64, (int) config('llm.max_tokens', 600));

        $resp = Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->post("{$base}/api/chat", [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'stream' => false,
                'options' => [
                    'num_predict' => $maxTokens,
                ],
            ]);

        if ($resp->failed()) {
            throw new LlmUnavailable("Ollama returned HTTP {$resp->status()} on /api/chat.");
        }

        $json = $resp->json();
        if (! is_array($json) || ! is_string($json['message']['content'] ?? null)) {
            throw new LlmUnavailable('Unexpected JSON structure from Ollama /api/chat.');
        }

        return [
            'content' => (string) $json['message']['content'],
            'input_tokens' => max(0, (int) ($json['prompt_eval_count'] ?? 0)),
            'output_tokens' => max(0, (int) ($json['eval_count'] ?? 0)),
        ];
    }

    /**
     * Verified against OpenAI Chat Completions API (`POST /v1/chat/completions`).
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     */
    private function callOpenAi(string $model, string $systemPrompt, string $userPrompt): array
    {
        $base = rtrim((string) config('llm.drivers.openai.base_url', 'https://api.openai.com/v1'), '/');
        $apiKey = trim((string) config('llm.drivers.openai.api_key', ''));
        $timeout = max(5, (int) config('llm.timeout', 60));
        $maxTokens = max(64, (int) config('llm.max_tokens', 600));

        $resp = Http::timeout($timeout)
            ->withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->post("{$base}/chat/completions", [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'max_tokens' => $maxTokens,
            ]);

        if ($resp->failed()) {
            throw new LlmUnavailable("OpenAI-compatible endpoint returned HTTP {$resp->status()}.");
        }

        $json = $resp->json();
        $text = $json['choices'][0]['message']['content'] ?? null;
        if (! is_array($json) || ! is_string($text)) {
            throw new LlmUnavailable('Unexpected JSON structure from OpenAI-compatible /chat/completions.');
        }

        return [
            'content' => $text,
            'input_tokens' => max(0, (int) ($json['usage']['prompt_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($json['usage']['completion_tokens'] ?? 0)),
        ];
    }

    /**
     * Verified against Anthropic Messages API (`POST /v1/messages`).
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     */
    private function callAnthropic(string $model, string $systemPrompt, string $userPrompt): array
    {
        $base = rtrim((string) config('llm.drivers.anthropic.base_url', 'https://api.anthropic.com'), '/');
        $apiKey = trim((string) config('llm.drivers.anthropic.api_key', ''));
        $version = (string) config('llm.drivers.anthropic.version', '2023-06-01');
        $timeout = max(5, (int) config('llm.timeout', 60));
        $maxTokens = max(64, (int) config('llm.max_tokens', 600));

        $resp = Http::timeout($timeout)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => $version,
            ])
            ->acceptJson()
            ->asJson()
            ->post("{$base}/v1/messages", [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($resp->failed()) {
            throw new LlmUnavailable("Anthropic returned HTTP {$resp->status()} on /v1/messages.");
        }

        $json = $resp->json();
        if (! is_array($json) || ! is_array($json['content'] ?? null)) {
            throw new LlmUnavailable('Unexpected JSON structure from Anthropic /v1/messages.');
        }

        $parts = [];
        foreach ($json['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $parts[] = (string) $block['text'];
            }
        }

        return [
            'content' => implode("\n", $parts),
            'input_tokens' => max(0, (int) ($json['usage']['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($json['usage']['output_tokens'] ?? 0)),
        ];
    }

    private function recordCall(
        int $tenantId,
        string $purpose,
        string $driver,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $latencyMs,
        string $status,
        ?string $error
    ): void {
        DB::table('llm_calls')->insert([
            'tenant_id' => $tenantId,
            'purpose' => mb_substr($purpose, 0, 60, 'UTF-8'),
            'driver' => mb_substr($driver, 0, 30, 'UTF-8'),
            'model' => mb_substr($model, 0, 100, 'UTF-8'),
            'input_tokens' => max(0, $inputTokens),
            'output_tokens' => max(0, $outputTokens),
            'latency_ms' => max(0, $latencyMs),
            'status' => mb_substr($status, 0, 30, 'UTF-8'),
            'error' => $error !== null ? mb_substr($error, 0, 255, 'UTF-8') : null,
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function sanitizeError(string $message): string
    {
        $clean = (string) preg_replace('/(sk-[A-Za-z0-9_-]{8,}|Bearer\s+\S+)/i', '[redacted]', $message);

        return mb_substr(trim($clean), 0, 250, 'UTF-8');
    }
}
