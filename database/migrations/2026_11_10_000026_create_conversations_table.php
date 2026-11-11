<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->char('session_token_hash', 64);
            $table->string('status', 20)->default('open'); // open | handoff | closed
            $table->dateTime('started_at');
            $table->dateTime('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['chatbot_id', 'session_token_hash']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
