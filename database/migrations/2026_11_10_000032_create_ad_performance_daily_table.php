<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_performance_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('import_batch_id')->nullable();
            $table->string('platform', 30); // meta | google | tiktok | other
            $table->string('campaign', 200);
            $table->date('date');
            $table->decimal('spend', 14, 2)->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('platform_conversions', 12, 2)->default(0);
            $table->decimal('platform_revenue', 14, 2)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'platform', 'campaign', 'date'], 'ad_perf_tenant_plat_camp_date_uq');
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'website_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_performance_daily');
    }
};
