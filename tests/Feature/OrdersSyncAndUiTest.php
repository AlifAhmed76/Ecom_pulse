<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Website;
use App\Services\CredentialService;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OrdersSyncAndUiTest extends TestCase
{
    use CreatesTenants;
    use RefreshDatabase;

    private function createWebsite(int $tenantId, string $name = 'Shop'): Website
    {
        $site = new Website([
            'name' => $name,
            'platform' => 'laravel',
            'domain' => 'shop-'.uniqid().'.test',
            'currency' => 'BDT',
        ]);
        $site->forceFill([
            'tenant_id' => $tenantId,
            'status' => 'active',
            'verification_token' => bin2hex(random_bytes(20)),
        ])->save();

        return $site;
    }

    private function postSigned(string $uri, array $payload, string $keyId, string $secret): TestResponse
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = "POST\n{$uri}\n{$ts}\n{$nonce}\n".hash('sha256', $rawBody);
        $sig = hash_hmac('sha256', $canonical, $secret);

        return $this->call('POST', $uri, [], [], [], [
            'HTTP_X_LYP_KEY' => $keyId,
            'HTTP_X_LYP_TIMESTAMP' => $ts,
            'HTTP_X_LYP_NONCE' => $nonce,
            'HTTP_X_LYP_SIGNATURE' => $sig,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $rawBody);
    }

    public function test_products_and_orders_idempotent_history_stale_items_totals_and_pii_discard(): void
    {
        $a = $this->makeTenantWithUsers('Commerce Alpha');
        $b = $this->makeTenantWithUsers('Commerce Beta');

        $siteA = $this->createWebsite($a['tenant']->id, 'Alpha Shop');
        $siteB = $this->createWebsite($b['tenant']->id, 'Beta Shop');

        $credA = CredentialService::issue($siteA, ['products:write', 'orders:write']);
        $credB = CredentialService::issue($siteB, ['orders:write']);
        $credHeartbeatOnly = CredentialService::issue($siteA, ['heartbeat']);

        // Scopes enforced
        $this->postSigned('/api/v1/connector/products', [
            'products' => [['id' => 'p1', 'name' => 'Polo']],
        ], $credHeartbeatOnly['credential']->key_id, $credHeartbeatOnly['secret'])
            ->assertStatus(403)
            ->assertExactJson(['ok' => false, 'error' => 'insufficient_scope']);

        // Sync product with cost=300 and stock=8 (low stock)
        $this->postSigned('/api/v1/connector/products', [
            'products' => [[
                'id' => 'p1',
                'name' => 'Cotton Polo',
                'sku' => 'POLO-01',
                'category' => 'Apparel',
                'price' => 500,
                'cost' => 300,
                'stock' => 8,
            ]],
        ], $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 1, 'updated' => 0]);

        // Re-sync omitting optional sku/cost/stock -> preserves existing values
        $this->postSigned('/api/v1/connector/products', [
            'products' => [[
                'id' => 'p1',
                'name' => 'Cotton Polo V2',
            ]],
        ], $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 0, 'updated' => 1]);

        $this->assertDatabaseHas('products', [
            'website_id' => $siteA->id,
            'external_id' => 'p1',
            'name' => 'Cotton Polo V2',
            'sku' => 'POLO-01',
            'cost' => 300,
            'stock' => 8,
        ]);

        // Validation: no items + no total => 422
        $this->postSigned('/api/v1/connector/orders', [
            'orders' => [[
                'id' => 'invalid-1',
                'status' => 'pending',
                'ordered_at' => '2026-11-01T10:00:00+06:00',
            ]],
        ], $credA['credential']->key_id, $credA['secret'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['orders.0.total']);

        $rawPhone = '01711223344';
        $rawEmail = 'rahim.buyer@example.com';

        Log::listen(function ($event) use ($rawPhone, $rawEmail): void {
            $blob = $event->message.(json_encode($event->context) ?: '');
            $this->assertStringNotContainsString($rawPhone, $blob);
            $this->assertStringNotContainsString($rawEmail, $blob);
        });

        // Order creation with injected website_id & tenant_id in body (must be ignored!)
        $orderPayload = [
            'website_id' => $siteB->id,
            'tenant_id' => $b['tenant']->id,
            'orders' => [[
                'id' => 'ORD-1001',
                'status' => 'wc-processing',
                'ordered_at' => '2026-11-01T10:00:00+06:00',
                'updated_at' => '2026-11-01T11:00:00+06:00',
                'discount' => 50,
                'shipping' => 60,
                'total' => null, // computes: (2*500) - 50 + 60 = 1010
                'courier' => 'Pathao',
                'city' => 'Dhaka',
                'customer' => ['id' => 'c1', 'name' => 'Rahim', 'phone' => '+8801711223344', 'email' => $rawEmail],
                'employee' => ['name' => 'Karim'],
                'items' => [[
                    'product_id' => 'p1',
                    'sku' => 'POLO-01',
                    'name' => 'Cotton Polo V2',
                    'quantity' => 2,
                    'unit_price' => 500,
                    // unit_cost omitted -> inherits 300 from product!
                ]],
            ]],
        ];

        $this->postSigned('/api/v1/connector/orders', $orderPayload, $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 1, 'updated' => 0, 'stale' => 0]);

        $order = Order::withoutGlobalScopes()->where('website_id', $siteA->id)->where('external_id', 'ORD-1001')->firstOrFail();
        $this->assertSame($a['tenant']->id, $order->tenant_id);
        $this->assertSame($siteA->id, $order->website_id);
        $this->assertSame('pending', $order->status);
        $this->assertSame('1010.00', $order->total);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'unit_cost' => 300,
            'line_total' => 1000,
        ]);

        // Re-sending identical order creates 0 duplicates & 0 extra history rows
        $this->postSigned('/api/v1/connector/orders', $orderPayload, $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 0, 'updated' => 1, 'stale' => 0]);
        $this->assertSame(1, DB::table('order_status_history')->where('order_id', $order->id)->count());

        // Stale update ignored (older updated_at)
        $stalePayload = $orderPayload;
        $stalePayload['orders'][0]['status'] = 'cancelled';
        $stalePayload['orders'][0]['updated_at'] = '2026-11-01T09:00:00+06:00';
        $this->postSigned('/api/v1/connector/orders', $stalePayload, $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 0, 'updated' => 0, 'stale' => 1]);
        $this->assertSame('pending', $order->fresh()->status);

        // Status change appends history row & item replacement replaces lines + explicit total authoritative
        $updatePayload = $orderPayload;
        $updatePayload['orders'][0]['status'] = 'Delivered';
        $updatePayload['orders'][0]['updated_at'] = '2026-11-02T12:00:00+06:00';
        $updatePayload['orders'][0]['total'] = 999; // explicit total overrides item sum
        $updatePayload['orders'][0]['items'] = [[
            'name' => 'Replacement Item',
            'quantity' => 1,
            'unit_price' => 700,
        ]];
        $this->postSigned('/api/v1/connector/orders', $updatePayload, $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 0, 'updated' => 1, 'stale' => 0]);

        $order->refresh();
        $this->assertSame('delivered', $order->status);
        $this->assertSame('999.00', $order->total);
        $this->assertSame(1, DB::table('order_items')->where('order_id', $order->id)->count());
        $this->assertSame(2, DB::table('order_status_history')->where('order_id', $order->id)->count());

        // Order without items with explicit total works
        $this->postSigned('/api/v1/connector/orders', [
            'orders' => [[
                'id' => 'ORD-NOITEMS',
                'status' => 'confirmed',
                'ordered_at' => '2026-11-02T14:00:00+06:00',
                'total' => 450,
            ]],
        ], $credA['credential']->key_id, $credA['secret'])
            ->assertOk()
            ->assertExactJson(['ok' => true, 'created' => 1, 'updated' => 0, 'stale' => 0]);

        // Send SAME phone to Tenant B -> customer_key differs per tenant, zero plaintext phone/email in DB
        $this->postSigned('/api/v1/connector/orders', [
            'orders' => [[
                'id' => 'BETA-1',
                'status' => 'pending',
                'ordered_at' => '2026-11-02T15:00:00+06:00',
                'total' => 300,
                'customer' => ['phone' => $rawPhone, 'email' => $rawEmail],
            ]],
        ], $credB['credential']->key_id, $credB['secret'])->assertOk();

        $custAKey = DB::table('customers')->where('tenant_id', $a['tenant']->id)->value('customer_key');
        $custBKey = DB::table('customers')->where('tenant_id', $b['tenant']->id)->value('customer_key');
        $this->assertNotNull($custAKey);
        $this->assertNotNull($custBKey);
        $this->assertNotSame($custAKey, $custBKey);
        $this->assertSame(SyncService::customerKeyFor($a['tenant']->id, $rawPhone), $custAKey);

        foreach (['customers', 'orders', 'order_items', 'audit_logs'] as $table) {
            $dump = json_encode(DB::table($table)->get()->toArray()) ?: '';
            $this->assertStringNotContainsString($rawPhone, $dump);
            $this->assertStringNotContainsString($rawEmail, $dump);
        }

        // Tenant isolation on web UI
        $this->actingAs($a['analyst'])->get('/orders')->assertOk()->assertSee('ORD-1001')->assertDontSee('BETA-1');
        $this->actingAs($a['analyst'])->get('/orders/'.$order->id)->assertOk()->assertSee('Replacement Item');
        $this->actingAs($b['owner'])->get('/orders/'.$order->id)->assertNotFound();
        $this->actingAs($a['analyst'])->get('/products?low_stock=1')->assertOk()->assertSee('Low stock');
    }

    public function test_orders_list_avoids_n_plus_one_queries(): void
    {
        $a = $this->makeTenantWithUsers('Perf Alpha');
        $site = $this->createWebsite($a['tenant']->id, 'Perf Shop');
        $sync = SyncService::for($a['tenant']->id);

        $seedBatch = function (int $start, int $count) use ($sync, $site): void {
            $orders = [];
            for ($i = $start; $i < $start + $count; $i++) {
                $orders[] = [
                    'id' => 'ORD-'.$i,
                    'status' => 'confirmed',
                    'ordered_at' => now()->subMinutes($i)->toIso8601String(),
                    'total' => 500,
                    'customer' => ['name' => 'Buyer '.$i, 'phone' => '0170000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)],
                    'employee' => ['name' => 'Agent '.($i % 3)],
                    'items' => [['name' => 'SKU '.$i, 'quantity' => 1, 'unit_price' => 500]],
                ];
            }
            $sync->upsertOrders($site, $orders);
        };

        $a['owner']->load('tenant');

        $seedBatch(1, 5);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($a['owner'])->get('/orders')->assertOk();
        $queriesAt5 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $seedBatch(6, 20); // now 25 orders (full page)
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($a['owner'])->get('/orders')->assertOk();
        $queriesAt25 = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(7, $queriesAt5);
        $this->assertSame($queriesAt5, $queriesAt25);
    }
}
