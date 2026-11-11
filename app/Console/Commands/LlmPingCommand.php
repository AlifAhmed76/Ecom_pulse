<?php

namespace App\Console\Commands;

use App\Services\Llm\LlmClient;
use Illuminate\Console\Command;

class LlmPingCommand extends Command
{
    protected $signature = 'llm:ping';

    protected $description = 'Test connectivity to the configured optional LLM provider (none | ollama | openai | anthropic)';

    public function handle(LlmClient $client): int
    {
        $res = $client->ping();

        $this->line("Driver : {$res['driver']}");
        $this->line('Model  : '.($res['model'] !== '' ? $res['model'] : '(none)'));
        $this->line("Latency: {$res['latency_ms']} ms");

        if ($res['ok']) {
            $this->info("OK: {$res['message']}");

            return self::SUCCESS;
        }

        $this->warn("NOT READY: {$res['message']}");

        return self::FAILURE;
    }
}
