<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('research_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();

            $table->string('rule_code', 10);
            $table->string('subject_key', 120);
            $table->string('week_key', 12);
            $table->char('fingerprint', 64);
            $table->boolean('is_insufficient_data')->default(false);

            $table->string('template_key', 48);
            $table->json('params');

            // 1. Finding (what)
            $table->string('title', 255);
            $table->text('summary');

            // 2. Evidence
            $table->json('evidence');

            // 3. Risk / priority
            $table->string('severity', 20); // critical | high | medium | low
            $table->decimal('money_at_stake', 14, 2)->nullable();
            $table->char('currency', 3)->default('BDT');

            // 4. Confidence
            $table->string('confidence', 20); // high | medium | low
            $table->string('confidence_reason', 255);

            // 5. Hypotheses (unverified)
            $table->json('hypotheses');

            // 6. Missing data
            $table->json('missing_data');

            // 7. Recommended action
            $table->text('recommended_action');

            // 8. Experiment plan
            $table->text('experiment_plan');

            // 9. Success metric
            $table->json('success_metric');

            // 10. Status & outcome
            $table->string('status', 20)->default('new');
            $table->text('outcome_note')->nullable();
            $table->string('dismiss_reason', 255)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();

            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'fingerprint']);
            $table->index(['tenant_id', 'status', 'severity']);
            $table->index(['tenant_id', 'website_id', 'rule_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_findings');
    }
};
