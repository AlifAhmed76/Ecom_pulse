<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('platform', 20)->default('laravel'); // laravel | file
            $table->string('domain', 253)->nullable()->unique();
            $table->string('status', 20)->default('pending'); // pending | active | disabled
            $table->char('currency', 3)->default('BDT');
            $table->string('verification_token', 64);
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('connector_version', 30)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
