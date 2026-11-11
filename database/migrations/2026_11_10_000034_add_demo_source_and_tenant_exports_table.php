<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('platform');
            $table->index(['tenant_id', 'source']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('source', 20)->default('connector')->after('active');
            $table->index(['tenant_id', 'source']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('source', 20)->default('connector')->after('name');
            $table->index(['tenant_id', 'source']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('source', 20)->default('manual')->after('name');
            $table->index(['tenant_id', 'source']);
        });

        Schema::table('courier_shipments', function (Blueprint $table) {
            $table->string('source', 20)->default('import')->after('matched');
            $table->index(['tenant_id', 'source']);
        });

        Schema::table('ad_performance_daily', function (Blueprint $table) {
            $table->string('source', 20)->default('import')->after('platform_revenue');
            $table->index(['tenant_id', 'source']);
        });

        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('queued'); // queued | processing | ready | failed
            $table->string('filename', 255);
            $table->string('stored_path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('rows_exported')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_exports');

        Schema::table('ad_performance_daily', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('courier_shipments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source']);
            $table->dropColumn('source');
        });
    }
};
