<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneCommand extends Command
{
    protected $signature = 'lyp:prune';

    protected $description = 'Prune request nonces older than 15 minutes';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(15)->format('Y-m-d H:i:s');
        $deleted = DB::table('request_nonces')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} expired nonce(s).");

        return self::SUCCESS;
    }
}
