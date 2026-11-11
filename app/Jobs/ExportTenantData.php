<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\TenantExport;
use App\Support\Csv;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportTenantData implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $exportId
    ) {
    }

    public function handle(): void
    {
        $export = TenantExport::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->exportId);

        if ($export === null) {
            return;
        }

        $export->forceFill(['status' => 'processing', 'error' => null])->save();
        $disk = Storage::disk('local');

        try {
            $datasets = $this->collectTenantDatasets($this->tenantId);
            $rowsTotal = 0;
            $files = [];

            foreach ($datasets as $filename => $dataset) {
                $rowsTotal += count($dataset['rows']);
                $files[$filename] = Csv::build($dataset['headers'], $dataset['rows']);
            }

            $zipBytes = $this->packZipBytes($files);
            $relativePath = "exports/t{$this->tenantId}/{$export->filename}";
            $disk->put($relativePath, $zipBytes);

            $export->forceFill([
                'status' => 'ready',
                'stored_path' => $relativePath,
                'size_bytes' => strlen($zipBytes),
                'rows_exported' => $rowsTotal,
                'expires_at' => now()->addHours(24),
                'error' => null,
            ])->save();

            AuditLog::record(
                'tenant.export.ready',
                "Generated tenant export archive #{$export->id} ({$rowsTotal} rows)",
                $this->tenantId,
                $export->requested_by !== null ? (int) $export->requested_by : null
            );
        } catch (Throwable $e) {
            $export->forceFill([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 480, 'UTF-8'),
            ])->save();
        }
    }

    /**
     * Collect tenant-only CSV datasets strictly scoped by $tenantId with secret fields excluded.
     *
     * @return array<string, array{headers: list<string>, rows: list<list<mixed>>}>
     */
    private function collectTenantDatasets(int $tenantId): array
    {
        $datasets = [];

        // 1. Tenant profile
        $datasets['tenant.csv'] = [
            'headers' => ['id', 'name', 'slug', 'created_at'],
            'rows' => DB::table('tenants')
                ->where('id', $tenantId)
                ->get(['id', 'name', 'slug', 'created_at'])
                ->map(fn (object $r): array => [$r->id, $r->name, $r->slug, $r->created_at])
                ->all(),
        ];

        // 2. Users (NEVER password or remember_token)
        $datasets['users.csv'] = [
            'headers' => ['id', 'name', 'email', 'role', 'created_at'],
            'rows' => DB::table('users')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'name', 'email', 'role', 'created_at'])
                ->map(fn (object $r): array => [$r->id, $r->name, $r->email, $r->role, $r->created_at])
                ->all(),
        ];

        // 3. Websites (NEVER verification_token)
        $datasets['websites.csv'] = [
            'headers' => ['id', 'name', 'platform', 'source', 'domain', 'status', 'currency', 'verified_at', 'last_heartbeat_at', 'connector_version', 'created_at'],
            'rows' => DB::table('websites')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'name', 'platform', 'source', 'domain', 'status', 'currency', 'verified_at', 'last_heartbeat_at', 'connector_version', 'created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->name, $r->platform, $r->source, $r->domain, $r->status,
                    $r->currency, $r->verified_at, $r->last_heartbeat_at, $r->connector_version, $r->created_at,
                ])
                ->all(),
        ];

        // 4. API Credentials metadata (NEVER secret_encrypted)
        $datasets['api_credentials.csv'] = [
            'headers' => ['id', 'website_id', 'key_id', 'scopes', 'expires_at', 'revoked_at', 'last_used_at', 'created_at'],
            'rows' => DB::table('api_credentials as ac')
                ->join('websites as w', 'w.id', '=', 'ac.website_id')
                ->where('w.tenant_id', $tenantId)
                ->orderBy('ac.id')
                ->get(['ac.id', 'ac.website_id', 'ac.key_id', 'ac.scopes', 'ac.expires_at', 'ac.revoked_at', 'ac.last_used_at', 'ac.created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->key_id, $r->scopes, $r->expires_at, $r->revoked_at, $r->last_used_at, $r->created_at,
                ])
                ->all(),
        ];

        // 5. Products
        $datasets['products.csv'] = [
            'headers' => ['id', 'website_id', 'external_id', 'sku', 'name', 'category', 'price', 'cost', 'stock', 'active', 'source', 'created_at'],
            'rows' => DB::table('products')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'external_id', 'sku', 'name', 'category', 'price', 'cost', 'stock', 'active', 'source', 'created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->external_id, $r->sku, $r->name, $r->category,
                    $r->price, $r->cost, $r->stock, $r->active, $r->source, $r->created_at,
                ])
                ->all(),
        ];

        // 6. Customers (hashed customer_key only; no raw phone/email exists)
        $datasets['customers.csv'] = [
            'headers' => ['id', 'customer_key', 'name', 'source', 'created_at'],
            'rows' => DB::table('customers')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'customer_key', 'name', 'source', 'created_at'])
                ->map(fn (object $r): array => [$r->id, $r->customer_key, $r->name, $r->source, $r->created_at])
                ->all(),
        ];

        // 7. Employees
        $datasets['employees.csv'] = [
            'headers' => ['id', 'name', 'source', 'created_at'],
            'rows' => DB::table('employees')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'name', 'source', 'created_at'])
                ->map(fn (object $r): array => [$r->id, $r->name, $r->source, $r->created_at])
                ->all(),
        ];

        // 8. Orders
        $datasets['orders.csv'] = [
            'headers' => [
                'id', 'website_id', 'external_id', 'customer_id', 'status', 'source_status',
                'ordered_at', 'subtotal', 'discount', 'shipping', 'total', 'currency',
                'courier', 'consignment_id', 'city', 'cancel_reason', 'utm_campaign',
                'status_changed_at', 'source', 'created_at',
            ],
            'rows' => DB::table('orders')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get([
                    'id', 'website_id', 'external_id', 'customer_id', 'status', 'source_status',
                    'ordered_at', 'subtotal', 'discount', 'shipping', 'total', 'currency',
                    'courier', 'consignment_id', 'city', 'cancel_reason', 'utm_campaign',
                    'status_changed_at', 'source', 'created_at',
                ])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->external_id, $r->customer_id, $r->status, $r->source_status,
                    $r->ordered_at, $r->subtotal, $r->discount, $r->shipping, $r->total, $r->currency,
                    $r->courier, $r->consignment_id, $r->city, $r->cancel_reason, $r->utm_campaign,
                    $r->status_changed_at, $r->source, $r->created_at,
                ])
                ->all(),
        ];

        // 9. Order items
        $datasets['order_items.csv'] = [
            'headers' => ['id', 'order_id', 'product_id', 'product_name', 'sku', 'quantity', 'unit_price', 'unit_cost', 'line_total'],
            'rows' => DB::table('order_items as oi')
                ->join('orders as o', 'o.id', '=', 'oi.order_id')
                ->where('o.tenant_id', $tenantId)
                ->orderBy('oi.id')
                ->get(['oi.id', 'oi.order_id', 'oi.product_id', 'oi.product_name', 'oi.sku', 'oi.quantity', 'oi.unit_price', 'oi.unit_cost', 'oi.line_total'])
                ->map(fn (object $r): array => [
                    $r->id, $r->order_id, $r->product_id, $r->product_name, $r->sku, $r->quantity, $r->unit_price, $r->unit_cost, $r->line_total,
                ])
                ->all(),
        ];

        // 10. Order assignments
        $datasets['order_assignments.csv'] = [
            'headers' => ['id', 'order_id', 'employee_id', 'assigned_at'],
            'rows' => DB::table('order_assignments as oa')
                ->join('orders as o', 'o.id', '=', 'oa.order_id')
                ->where('o.tenant_id', $tenantId)
                ->orderBy('oa.id')
                ->get(['oa.id', 'oa.order_id', 'oa.employee_id', 'oa.assigned_at'])
                ->map(fn (object $r): array => [$r->id, $r->order_id, $r->employee_id, $r->assigned_at])
                ->all(),
        ];

        // 11. Order status history
        $datasets['order_status_history.csv'] = [
            'headers' => ['id', 'order_id', 'from_status', 'to_status', 'changed_at', 'employee_id', 'source'],
            'rows' => DB::table('order_status_history as h')
                ->join('orders as o', 'o.id', '=', 'h.order_id')
                ->where('o.tenant_id', $tenantId)
                ->orderBy('h.id')
                ->get(['h.id', 'h.order_id', 'h.from_status', 'h.to_status', 'h.changed_at', 'h.employee_id', 'h.source'])
                ->map(fn (object $r): array => [
                    $r->id, $r->order_id, $r->from_status, $r->to_status, $r->changed_at, $r->employee_id, $r->source,
                ])
                ->all(),
        ];

        // 12. Courier shipments
        $datasets['courier_shipments.csv'] = [
            'headers' => ['id', 'website_id', 'courier', 'consignment_id', 'order_ref', 'order_id', 'customer_key', 'status', 'source_status', 'cod_amount', 'collected_amount', 'delivery_fee', 'return_fee', 'area', 'shipped_at', 'delivered_at', 'returned_at', 'matched', 'match_method', 'source'],
            'rows' => DB::table('courier_shipments')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'courier', 'consignment_id', 'order_ref', 'order_id', 'customer_key', 'status', 'source_status', 'cod_amount', 'collected_amount', 'delivery_fee', 'return_fee', 'area', 'shipped_at', 'delivered_at', 'returned_at', 'matched', 'match_method', 'source'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->courier, $r->consignment_id, $r->order_ref, $r->order_id,
                    $r->customer_key, $r->status, $r->source_status, $r->cod_amount, $r->collected_amount,
                    $r->delivery_fee, $r->return_fee, $r->area, $r->shipped_at, $r->delivered_at,
                    $r->returned_at, $r->matched, $r->match_method, $r->source,
                ])
                ->all(),
        ];

        // 13. Risk flags
        $datasets['risk_flags.csv'] = [
            'headers' => ['id', 'order_id', 'customer_id', 'rule', 'status', 'reviewed_at', 'review_note', 'evidence', 'created_at'],
            'rows' => DB::table('risk_flags')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'order_id', 'customer_id', 'rule', 'status', 'reviewed_at', 'review_note', 'evidence', 'created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->order_id, $r->customer_id, $r->rule, $r->status, $r->reviewed_at, $r->review_note, $r->evidence, $r->created_at,
                ])
                ->all(),
        ];

        // 14. R&D findings
        $datasets['research_findings.csv'] = [
            'headers' => ['id', 'website_id', 'rule_code', 'template_key', 'subject_key', 'week_key', 'severity', 'confidence', 'money_at_stake', 'currency', 'status', 'is_insufficient_data', 'outcome_note', 'dismiss_reason', 'first_seen_at', 'last_seen_at'],
            'rows' => DB::table('research_findings')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'rule_code', 'template_key', 'subject_key', 'week_key', 'severity', 'confidence', 'money_at_stake', 'currency', 'status', 'is_insufficient_data', 'outcome_note', 'dismiss_reason', 'first_seen_at', 'last_seen_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->rule_code, $r->template_key, $r->subject_key, $r->week_key,
                    $r->severity, $r->confidence, $r->money_at_stake, $r->currency, $r->status,
                    $r->is_insufficient_data, $r->outcome_note, $r->dismiss_reason, $r->first_seen_at, $r->last_seen_at,
                ])
                ->all(),
        ];

        // 15. Experiments & results
        $datasets['experiments.csv'] = [
            'headers' => ['id', 'website_id', 'finding_id', 'title', 'hypothesis', 'design', 'status', 'min_sample', 'planned_days', 'approved_at', 'started_at', 'ended_at', 'baseline', 'success_metric'],
            'rows' => DB::table('experiments')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'finding_id', 'title', 'hypothesis', 'design', 'status', 'min_sample', 'planned_days', 'approved_at', 'started_at', 'ended_at', 'baseline', 'success_metric'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->finding_id, $r->title, $r->hypothesis, $r->design,
                    $r->status, $r->min_sample, $r->planned_days, $r->approved_at, $r->started_at, $r->ended_at,
                    $r->baseline, $r->success_metric,
                ])
                ->all(),
        ];

        $datasets['experiment_results.csv'] = [
            'headers' => ['id', 'experiment_id', 'outcome', 'lesson', 'recorded_at', 'measured'],
            'rows' => DB::table('experiment_results')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'experiment_id', 'outcome', 'lesson', 'recorded_at', 'measured'])
                ->map(fn (object $r): array => [$r->id, $r->experiment_id, $r->outcome, $r->lesson, $r->recorded_at, $r->measured])
                ->all(),
        ];

        // 16. Ad performance
        $datasets['ad_performance_daily.csv'] = [
            'headers' => ['id', 'website_id', 'platform', 'campaign', 'date', 'spend', 'impressions', 'clicks', 'platform_conversions', 'platform_revenue', 'source'],
            'rows' => DB::table('ad_performance_daily')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'platform', 'campaign', 'date', 'spend', 'impressions', 'clicks', 'platform_conversions', 'platform_revenue', 'source'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->platform, $r->campaign, $r->date, $r->spend,
                    $r->impressions, $r->clicks, $r->platform_conversions, $r->platform_revenue, $r->source,
                ])
                ->all(),
        ];

        // 17. Chatbots, KB, consented leads (hashed contact only; NEVER export contact_enc)
        $datasets['chatbots.csv'] = [
            'headers' => ['id', 'website_id', 'name', 'enabled', 'widget_domain', 'public_key', 'handoff_text', 'created_at'],
            'rows' => DB::table('chatbots')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'name', 'enabled', 'widget_domain', 'public_key', 'handoff_text', 'created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->name, $r->enabled, $r->widget_domain, $r->public_key, $r->handoff_text, $r->created_at,
                ])
                ->all(),
        ];

        $datasets['knowledge_documents.csv'] = [
            'headers' => ['id', 'chatbot_id', 'title', 'body', 'source', 'updated_at'],
            'rows' => DB::table('knowledge_documents')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'chatbot_id', 'title', 'body', 'source', 'updated_at'])
                ->map(fn (object $r): array => [$r->id, $r->chatbot_id, $r->title, $r->body, $r->source, $r->updated_at])
                ->all(),
        ];

        $datasets['chatbot_leads.csv'] = [
            'headers' => ['id', 'chatbot_id', 'conversation_id', 'name', 'contact_hash', 'consent_at', 'created_at'],
            'rows' => DB::table('chatbot_leads')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'chatbot_id', 'conversation_id', 'name', 'contact_hash', 'consent_at', 'created_at'])
                ->map(fn (object $r): array => [$r->id, $r->chatbot_id, $r->conversation_id, $r->name, $r->contact_hash, $r->consent_at, $r->created_at])
                ->all(),
        ];

        // 18. Security events (tenant-owned only)
        $datasets['security_events.csv'] = [
            'headers' => ['id', 'website_id', 'type', 'severity', 'ip_hash', 'path', 'review_status', 'meta', 'created_at'],
            'rows' => DB::table('security_events')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'website_id', 'type', 'severity', 'ip_hash', 'path', 'review_status', 'meta', 'created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->website_id, $r->type, $r->severity, $r->ip_hash, $r->path, $r->review_status, $r->meta, $r->created_at,
                ])
                ->all(),
        ];

        // 19. Weekly reports
        $datasets['weekly_reports.csv'] = [
            'headers' => ['id', 'week_start', 'week_end', 'built_at', 'payload'],
            'rows' => DB::table('weekly_reports')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'week_start', 'week_end', 'built_at', 'payload'])
                ->map(fn (object $r): array => [$r->id, $r->week_start, $r->week_end, $r->built_at, $r->payload])
                ->all(),
        ];

        // 20. Audit logs scoped strictly to this tenant
        $datasets['audit_logs.csv'] = [
            'headers' => ['id', 'user_id', 'action', 'details', 'prev_hash', 'hash', 'created_at'],
            'rows' => DB::table('audit_logs')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->get(['id', 'user_id', 'action', 'details', 'prev_hash', 'hash', 'created_at'])
                ->map(fn (object $r): array => [
                    $r->id, $r->user_id, $r->action, $r->details, $r->prev_hash, $r->hash, $r->created_at,
                ])
                ->all(),
        ];

        return $datasets;
    }

    /**
     * Build standard PKZIP bytes using ZipArchive if available or zlib deflate fallback.
     *
     * @param  array<string, string>  $files
     */
    private function packZipBytes(array $files): string
    {
        if (class_exists(\ZipArchive::class)) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'lypexp_');
            if ($tmpFile !== false) {
                $zip = new \ZipArchive();
                if ($zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
                    foreach ($files as $name => $content) {
                        $zip->addFromString($name, $content);
                    }
                    $zip->close();
                    $bytes = file_get_contents($tmpFile);
                    @unlink($tmpFile);
                    if (is_string($bytes)) {
                        return $bytes;
                    }
                } else {
                    @unlink($tmpFile);
                }
            }
        }

        // Pure-PHP PKZIP fallback (deflate via always-enabled zlib extension)
        $localData = '';
        $centralDir = '';
        $offset = 0;
        $count = 0;

        foreach ($files as $name => $content) {
            $uncompressedLen = strlen($content);
            $crc = crc32($content);
            $compressed = gzdeflate($content, 6);
            $method = 8; // deflate
            if ($compressed === false) {
                $compressed = $content;
                $method = 0;
            }
            $compressedLen = strlen($compressed);
            $nameLen = strlen($name);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034B50,
                20,
                0x0800, // UTF-8 filename flag
                $method,
                0,
                0,
                $crc,
                $compressedLen,
                $uncompressedLen,
                $nameLen,
                0
            ).$name;

            $localData .= $localHeader.$compressed;

            $centralHeader = pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014B50,
                20,
                20,
                0x0800,
                $method,
                0,
                0,
                $crc,
                $compressedLen,
                $uncompressedLen,
                $nameLen,
                0,
                0,
                0,
                0,
                0,
                $offset
            ).$name;

            $centralDir .= $centralHeader;
            $offset += strlen($localHeader) + $compressedLen;
            $count++;
        }

        $centralLen = strlen($centralDir);
        $endRecord = pack(
            'VvvvvVVv',
            0x06054B50,
            0,
            0,
            $count,
            $count,
            $centralLen,
            $offset,
            0
        );

        return $localData.$centralDir.$endRecord;
    }
}
