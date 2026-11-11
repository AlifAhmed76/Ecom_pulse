<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->boolean('enabled')->default(true);
            $table->string('widget_domain', 253);
            $table->string('public_key', 48)->unique();
            $table->text('handoff_text');
            $table->timestamps();

            $table->index(['tenant_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbots');
    }
};
