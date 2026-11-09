<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Website;
use App\Services\Sync\SyncService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectorController extends Controller
{
    private const MAX_EVENT_PAYLOAD_BYTES = 65_536;

    public function heartbeat(Request $request): JsonResponse
    {
        /** @var Website $website */
        $website = $request->attributes->get('connector_website');

        $data = $request->validate([
            'connector_version' => ['nullable', 'string', 'max:30'],
        ]);

        $website->forceFill([
            'last_heartbeat_at' => now(),
            'connector_version' => $data['connector_version'] ?? $website->connector_version,
            'status' => $website->status === 'pending' ? 'active' : $website->status,
        ])->save();

        return response()->json([
            'ok' => true,
            'api_version' => 'v1',
            'server_time' => now()->toIso8601String(),
        ], 200);
    }

    public function events(Request $request): JsonResponse
    {
        /** @var Website $website */
        $website = $request->attributes->get('connector_website');

        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.id' => ['required', 'string', 'max:120'],
            'events.*.type' => ['required', 'string', 'max:80'],
            'events.*.occurred_at' => ['nullable', 'date'],
            'events.*.payload' => ['nullable', 'array'],
        ]);

        $nowStr = now()->format('Y-m-d H:i:s');
        $tz = config('app.timezone', 'Asia/Dhaka');
        $rows = [];

        foreach ($data['events'] as $index => $event) {
            $payloadJson = json_encode($event['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payloadJson === false || strlen($payloadJson) > self::MAX_EVENT_PAYLOAD_BYTES) {
                throw ValidationException::withMessages([
                    "events.{$index}.payload" => ['Event payload must not exceed 64 KB.'],
                ]);
            }

            $occurredAt = isset($event['occurred_at']) && $event['occurred_at'] !== null
                ? Carbon::parse($event['occurred_at'])->timezone($tz)->format('Y-m-d H:i:s')
                : null;

            $rows[] = [
                'website_id' => $website->id,
                'source_event_id' => (string) $event['id'],
                'type' => (string) $event['type'],
                'occurred_at' => $occurredAt,
                'payload' => $payloadJson,
                'status' => 'received',
                'received_at' => $nowStr,
            ];
        }

        $accepted = 0;
        $duplicates = 0;

        DB::transaction(function () use ($rows, &$accepted, &$duplicates): void {
            foreach ($rows as $row) {
                $inserted = DB::table('integration_events')->insertOrIgnore($row);
                if ($inserted === 1) {
                    $accepted++;
                } else {
                    $duplicates++;
                }
            }
        });

        return response()->json([
            'ok' => true,
            'accepted' => $accepted,
            'duplicates' => $duplicates,
        ], 202);
    }

    public function products(Request $request): JsonResponse
    {
        /** @var Website $website */
        $website = $request->attributes->get('connector_website');

        $data = $request->validate([
            'products' => ['required', 'array', 'min:1', 'max:200'],
            'products.*.id' => ['required', 'string', 'max:120'],
            'products.*.name' => ['required', 'string', 'max:255'],
            'products.*.sku' => ['nullable', 'string', 'max:100'],
            'products.*.category' => ['nullable', 'string', 'max:120'],
            'products.*.price' => ['nullable', 'numeric', 'min:0'],
            'products.*.cost' => ['nullable', 'numeric', 'min:0'],
            'products.*.stock' => ['nullable', 'integer', 'min:0'],
            'products.*.active' => ['nullable', 'boolean'],
        ]);

        $counts = SyncService::for($website->tenant_id)->upsertProducts($website, $data['products']);

        return response()->json([
            'ok' => true,
            'created' => $counts['created'],
            'updated' => $counts['updated'],
        ], 200);
    }

    public function orders(Request $request): JsonResponse
    {
        /** @var Website $website */
        $website = $request->attributes->get('connector_website');

        $data = $request->validate([
            'orders' => ['required', 'array', 'min:1', 'max:100'],
            'orders.*.id' => ['required', 'string', 'max:120'],
            'orders.*.status' => ['required', 'string', 'max:80'],
            'orders.*.ordered_at' => ['required', 'date'],
            'orders.*.updated_at' => ['nullable', 'date'],
            'orders.*.status_changed_at' => ['nullable', 'date'],
            'orders.*.currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'orders.*.discount' => ['nullable', 'numeric', 'min:0'],
            'orders.*.shipping' => ['nullable', 'numeric', 'min:0'],
            'orders.*.total' => ['nullable', 'numeric', 'min:0'],
            'orders.*.courier' => ['nullable', 'string', 'max:80'],
            'orders.*.consignment_id' => ['nullable', 'string', 'max:120'],
            'orders.*.city' => ['nullable', 'string', 'max:100'],
            'orders.*.cancel_reason' => ['nullable', 'string', 'max:255'],
            'orders.*.utm_campaign' => ['nullable', 'string', 'max:120'],
            'orders.*.customer' => ['nullable', 'array'],
            'orders.*.customer.id' => ['nullable', 'string', 'max:120'],
            'orders.*.customer.name' => ['nullable', 'string', 'max:150'],
            'orders.*.customer.phone' => ['nullable', 'string', 'max:50'],
            'orders.*.customer.email' => ['nullable', 'string', 'max:150'],
            'orders.*.employee' => ['nullable', 'array'],
            'orders.*.employee.name' => ['nullable', 'string', 'max:150'],
            'orders.*.items' => ['nullable', 'array'],
            'orders.*.items.*.product_id' => ['nullable', 'string', 'max:120'],
            'orders.*.items.*.sku' => ['nullable', 'string', 'max:100'],
            'orders.*.items.*.name' => ['required_with:orders.*.items', 'string', 'max:255'],
            'orders.*.items.*.quantity' => ['required_with:orders.*.items', 'integer', 'min:1'],
            'orders.*.items.*.unit_price' => ['required_with:orders.*.items', 'numeric', 'min:0'],
            'orders.*.items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        foreach ($data['orders'] as $idx => $order) {
            $hasItems = ! empty($order['items']);
            $hasTotal = array_key_exists('total', $order) && $order['total'] !== null;
            if (! $hasItems && ! $hasTotal) {
                throw ValidationException::withMessages([
                    "orders.{$idx}.total" => ['Total is required when an order has no items.'],
                ]);
            }
        }

        $counts = SyncService::for($website->tenant_id)->upsertOrders($website, $data['orders'], 'connector');

        return response()->json([
            'ok' => true,
            'created' => $counts['created'],
            'updated' => $counts['updated'],
            'stale' => $counts['stale'],
        ], 200);
    }
}
