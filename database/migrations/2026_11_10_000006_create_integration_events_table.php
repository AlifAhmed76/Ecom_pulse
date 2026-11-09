<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('source_event_id', 120);
            $table->string('type', 80);
            $table->dateTime('occurred_at')->nullable();
            $table->json('payload');
            $table->string('status', 30)->default('received');
            $table->dateTime('received_at');

            $table->unique(['website_id', 'source_event_id']);
            $table->index(['website_id', 'received_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
