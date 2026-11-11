<?php

namespace App\Jobs;

use App\Models\ResearchFinding;
use App\Services\Llm\LlmBudgetExceeded;
use App\Services\Llm\LlmClient;
use App\Services\Llm\LlmUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunLlmExplanation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $findingId,
        public readonly string $locale = 'en'
    ) {
        $this->onQueue('ai');
    }

    public function handle(?LlmClient $client = null): void
    {
        // Explicit tenant filter required in non-web queued jobs.
        $finding = ResearchFinding::withoutGlobalScopes()
            ->with('website:id,name,currency')
            ->where('tenant_id', $this->tenantId)
            ->find($this->findingId);

        if ($finding === null) {
            return;
        }

        $llm = $client ?? app(LlmClient::class);

        try {
            $res = $llm->explainFinding($finding, $this->locale);

            // Only update non-metric advisory columns; NEVER change numbers, severity, confidence, or status.
            $finding->forceFill([
                'ai_note' => $res['note'],
                'ai_model' => $res['model'],
                'ai_generated_at' => now(),
                'ai_error' => null,
            ])->save();
        } catch (LlmBudgetExceeded|LlmUnavailable $e) {
            $finding->forceFill([
                'ai_error' => mb_substr($e->getMessage(), 0, 250, 'UTF-8'),
            ])->save();
        } catch (Throwable $e) {
            $finding->forceFill([
                'ai_error' => 'AI explanation unavailable right now.',
            ])->save();
        }
    }
}
