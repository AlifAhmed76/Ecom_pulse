<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('rule', 10); // S1 | S2 | S3
            $table->char('fingerprint', 64);
            $table->json('evidence');
            $table->string('status', 20)->default('open'); // open | reviewed_ok | confirmed
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'fingerprint']);
            $table->index(['tenant_id', 'status', 'rule']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_flags');
    }
};
