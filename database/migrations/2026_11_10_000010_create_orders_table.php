<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 120);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30);
            $table->string('source_status', 80)->nullable();
            $table->dateTime('ordered_at');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('shipping', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->char('currency', 3)->default('BDT');
            $table->string('courier', 80)->nullable();
            $table->string('consignment_id', 120)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->string('utm_campaign', 120)->nullable();
            $table->dateTime('status_changed_at')->nullable();
            $table->dateTime('source_updated_at')->nullable();
            $table->string('source', 20)->default('connector');
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'external_id']);
            $table->index(['tenant_id', 'ordered_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['website_id', 'ordered_at']);
            $table->index(['tenant_id', 'courier']);
            $table->index(['tenant_id', 'import_batch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
