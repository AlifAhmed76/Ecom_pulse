<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Support\TenantCache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DeleteTenantData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly ?int $actorUserId,
        public readonly string $tenantSlug
    ) {
    }

    public function handle(): void
    {
        $tenantId = $this->tenantId;
        $exists = DB::table('tenants')->where('id', $tenantId)->exists();
        if (! $exists) {
            return;
        }

        $disk = Storage::disk('local');
        $disk->deleteDirectory("imports/t{$tenantId}");
        $disk->deleteDirectory("exports/t{$tenantId}");

        DB::transaction(function () use ($tenantId): void {
            // Delete in reverse dependency order so full cleanup succeeds on SQLite/MySQL/PostgreSQL
            DB::table('chat_messages')->where('tenant_id', $tenantId)->delete();
            DB::table('ai_action_logs')->where('tenant_id', $tenantId)->delete();
            DB::table('chatbot_leads')->where('tenant_id', $tenantId)->delete();
            DB::table('conversations')->where('tenant_id', $tenantId)->delete();
            DB::table('knowledge_documents')->where('tenant_id', $tenantId)->delete();
            DB::table('chatbots')->where('tenant_id', $tenantId)->delete();

            DB::table('experiment_results')->where('tenant_id', $tenantId)->delete();
            DB::table('experiments')->where('tenant_id', $tenantId)->delete();
            DB::table('research_findings')->where('tenant_id', $tenantId)->delete();
            DB::table('risk_flags')->where('tenant_id', $tenantId)->delete();

            DB::table('courier_shipments')->where('tenant_id', $tenantId)->delete();
            DB::table('ad_performance_daily')->where('tenant_id', $tenantId)->delete();

            DB::table('order_status_history')
                ->whereIn('order_id', function ($q) use ($tenantId): void {
                    $q->select('id')->from('orders')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('order_assignments')
                ->whereIn('order_id', function ($q) use ($tenantId): void {
                    $q->select('id')->from('orders')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('order_items')
                ->whereIn('order_id', function ($q) use ($tenantId): void {
                    $q->select('id')->from('orders')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('orders')->where('tenant_id', $tenantId)->delete();

            DB::table('products')->where('tenant_id', $tenantId)->delete();
            DB::table('employees')->where('tenant_id', $tenantId)->delete();
            DB::table('customers')->where('tenant_id', $tenantId)->delete();
            DB::table('import_batches')->where('tenant_id', $tenantId)->delete();
            DB::table('analyses')->where('tenant_id', $tenantId)->delete();
            DB::table('weekly_reports')->where('tenant_id', $tenantId)->delete();
            DB::table('tenant_exports')->where('tenant_id', $tenantId)->delete();
            DB::table('llm_calls')->where('tenant_id', $tenantId)->delete();
            DB::table('security_events')->where('tenant_id', $tenantId)->delete();
            DB::table('security_rules')->where('tenant_id', $tenantId)->delete();

            DB::table('integration_events')
                ->whereIn('website_id', function ($q) use ($tenantId): void {
                    $q->select('id')->from('websites')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('request_nonces')
                ->whereIn('credential_id', function ($q) use ($tenantId): void {
                    $q->select('ac.id')
                        ->from('api_credentials as ac')
                        ->join('websites as w', 'w.id', '=', 'ac.website_id')
                        ->where('w.tenant_id', $tenantId);
                })
                ->delete();
            DB::table('api_credentials')
                ->whereIn('website_id', function ($q) use ($tenantId): void {
                    $q->select('id')->from('websites')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('websites')->where('tenant_id', $tenantId)->delete();

            DB::table('sessions')
                ->whereIn('user_id', function ($q) use ($tenantId): void {
                    $q->select('id')->from('users')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('password_reset_tokens')
                ->whereIn('email', function ($q) use ($tenantId): void {
                    $q->select('email')->from('users')->where('tenant_id', $tenantId);
                })
                ->delete();
            DB::table('users')->where('tenant_id', $tenantId)->delete();

            DB::table('tenants')->where('id', $tenantId)->delete();
        });

        TenantCache::clear($tenantId);

        // Preserve tamper-evident hash chain by appending completion audit entry without touching existing chain rows
        AuditLog::record(
            'tenant.delete.completed',
            "Deleted workspace '{$this->tenantSlug}' (#{$tenantId}) and cascaded all tenant data",
            $tenantId,
            null
        );
    }
}
