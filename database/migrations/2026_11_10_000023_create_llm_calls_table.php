<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('llm_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 60);
            $table->string('driver', 30);
            $table->string('model', 100);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status', 30); // ok | error | budget_exceeded | rejected_oversized
            $table->string('error', 255)->nullable();
            $table->dateTime('created_at');

            // Strictly NO prompt or answer columns stored in this ledger.
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('llm_calls');
    }
};
