<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('customer_key', 64);
            $table->string('name', 150)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'customer_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
