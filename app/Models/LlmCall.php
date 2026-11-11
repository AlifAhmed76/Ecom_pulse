<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LlmCall extends Model
{
    use BelongsToTenant, HasFactory;

    public $timestamps = false;

    /**
     * Metadata ledger only; never stores prompts or completion responses.
     *
     * @var list<string>
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
