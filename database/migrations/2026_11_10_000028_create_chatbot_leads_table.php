<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->string('name', 150)->nullable();
            $table->char('contact_hash', 64);
            $table->text('contact_enc');
            $table->dateTime('consent_at');
            $table->dateTime('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'contact_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_leads');
    }
};
