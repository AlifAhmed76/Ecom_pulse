<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->string('sku', 100)->nullable();
            $table->string('name', 255);
            $table->string('category', 120)->nullable();
            $table->decimal('price', 14, 2)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->integer('stock')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['website_id', 'external_id']);
            $table->index(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
