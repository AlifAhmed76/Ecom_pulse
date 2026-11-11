<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chatbot_id')->constrained('chatbots')->cascadeOnDelete();
            $table->string('title', 200);
            $table->mediumText('body');
            $table->string('source', 20)->default('manual'); // manual | file
            $table->timestamps();

            $table->index(['tenant_id', 'chatbot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
