<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_nonces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credential_id')->constrained('api_credentials')->cascadeOnDelete();
            $table->string('nonce', 64);
            $table->timestamp('created_at');

            $table->unique(['credential_id', 'nonce']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_nonces');
    }
};
