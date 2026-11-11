<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->string('role', 20); // user | bot | agent
            $table->text('body');
            $table->boolean('grounded')->default(false);
            $table->foreignId('source_doc_id')->nullable()->constrained('knowledge_documents')->nullOnDelete();
            $table->dateTime('created_at');

            $table->index(['tenant_id', 'conversation_id']);
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
