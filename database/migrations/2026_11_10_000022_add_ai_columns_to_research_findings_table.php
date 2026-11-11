<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('research_findings', function (Blueprint $table) {
            $table->text('ai_note')->nullable()->after('outcome_note');
            $table->string('ai_model', 100)->nullable()->after('ai_note');
            $table->dateTime('ai_generated_at')->nullable()->after('ai_model');
            $table->string('ai_error', 255)->nullable()->after('ai_generated_at');
        });
    }

    public function down(): void
    {
        Schema::table('research_findings', function (Blueprint $table) {
            $table->dropColumn(['ai_note', 'ai_model', 'ai_generated_at', 'ai_error']);
        });
    }
};
