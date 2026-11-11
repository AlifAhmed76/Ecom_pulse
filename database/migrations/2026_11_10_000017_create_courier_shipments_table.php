<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('courier', 80);
            $table->string('consignment_id', 120)->nullable();
            $table->string('order_ref', 120)->nullable();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->char('customer_key', 64)->nullable();
            $table->string('status', 30);
            $table->string('source_status', 80)->nullable();
            $table->decimal('cod_amount', 14, 2)->nullable();
            $table->decimal('collected_amount', 14, 2)->nullable();
            $table->decimal('delivery_fee', 14, 2)->nullable();
            $table->decimal('return_fee', 14, 2)->nullable();
            $table->string('area', 100)->nullable();
            $table->dateTime('shipped_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->boolean('matched')->default(false);
            $table->string('match_method', 30)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'courier']);
            $table->index('order_id');
            $table->index(['tenant_id', 'import_batch_id']);
            $table->index(['tenant_id', 'matched']);
            $table->index(['tenant_id', 'consignment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_shipments');
    }
};
