<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Chatbot extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KnowledgeDocument::class, 'chatbot_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'chatbot_id')->latest('id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(ChatbotLead::class, 'chatbot_id')->latest('id');
    }
}
