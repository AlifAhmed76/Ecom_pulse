<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            $table->string('severity', 20); // info | low | medium | high
            $table->char('ip_hash', 64);
            $table->string('path', 255);
            $table->json('meta');
            $table->string('review_status', 30)->default('open'); // open | false_positive | confirmed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
            $table->index(['tenant_id', 'review_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
