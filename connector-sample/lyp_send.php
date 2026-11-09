<?php
/**
 * Reference client for the Last Year Project Connector API v1.
 *
 * Usage:
 *   php connector-sample/lyp_send.php http://127.0.0.1:8000 lyp_... lys_...
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI.\n");
    exit(1);
}

$baseUrl = rtrim($argv[1] ?? getenv('LYP_BASE_URL') ?: 'http://127.0.0.1:8000', '/');
$keyId = $argv[2] ?? getenv('LYP_KEY') ?: '';
$secret = $argv[3] ?? getenv('LYP_SECRET') ?: '';

if ($keyId === '' || $secret === '') {
    fwrite(STDERR, "Usage: php connector-sample/lyp_send.php <base_url> <key_id> <secret>\n");
    exit(1);
}

/**
 * Send a signed POST request with fresh timestamp and nonce.
 * Always use a NEW nonce and the SAME idempotency IDs on retries.
 *
 * @return array{status: int, body: string}
 */
function lyp_signed_post(string $baseUrl, string $path, array $payload, string $keyId, string $secret): array
{
    $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($rawBody === false) {
        throw new RuntimeException('Failed to encode request payload.');
    }

    $timestamp = (string) time();
    $nonce = bin2hex(random_bytes(16)); // 32 hex chars in [A-Za-z0-9_-]
    $canonical = implode("\n", [
        'POST',
        $path,
        $timestamp,
        $nonce,
        hash('sha256', $rawBody),
    ]);
    $signature = hash_hmac('sha256', $canonical, $secret);

    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-LYP-Key: '.$keyId,
        'X-LYP-Timestamp: '.$timestamp,
        'X-LYP-Nonce: '.$nonce,
        'X-LYP-Signature: '.$signature,
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers)."\r\n",
            'content' => $rawBody,
            'ignore_errors' => true,
            'timeout' => 15,
        ],
    ]);

    $responseBody = @file_get_contents($baseUrl.$path, false, $context);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    return [
        'status' => $status,
        'body' => $responseBody === false ? '' : $responseBody,
    ];
}

// 1. Heartbeat (200)
$hb = lyp_signed_post($baseUrl, '/api/v1/connector/heartbeat', [
    'connector_version' => '1.0.0',
], $keyId, $secret);
echo "Heartbeat HTTP {$hb['status']}: {$hb['body']}\n";

// 2. Events (202 -> duplicates on retry)
$eventId = 'evt_sample_'.date('Ymd_His');
$eventsPayload = [
    'events' => [
        [
            'id' => $eventId,
            'type' => 'order.viewed',
            'occurred_at' => date(DATE_ATOM),
            'payload' => ['page' => 'checkout', 'cart_items' => 2],
        ],
    ],
];
$ev1 = lyp_signed_post($baseUrl, '/api/v1/connector/events', $eventsPayload, $keyId, $secret);
echo "Events (1st) HTTP {$ev1['status']}: {$ev1['body']}\n";
$ev2 = lyp_signed_post($baseUrl, '/api/v1/connector/events', $eventsPayload, $keyId, $secret);
echo "Events (retry) HTTP {$ev2['status']}: {$ev2['body']}\n";

// 3. Products (requires products:write scope)
$pr = lyp_signed_post($baseUrl, '/api/v1/connector/products', [
    'products' => [
        [
            'id' => 'prod_sample_1',
            'sku' => 'SMP-POLO-01',
            'name' => 'Sample Cotton Polo',
            'category' => 'Apparel',
            'price' => 550,
            'cost' => 300,
            'stock' => 24,
            'active' => true,
        ],
    ],
], $keyId, $secret);
echo "Products HTTP {$pr['status']}: {$pr['body']}\n";

// 4. Orders (requires orders:write scope; idempotent re-send)
$ordersPayload = [
    'orders' => [
        [
            'id' => 'ord_sample_1001',
            'status' => 'Delivered',
            'ordered_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'currency' => 'BDT',
            'discount' => 0,
            'shipping' => 60,
            'total' => null,
            'courier' => 'Pathao',
            'consignment_id' => 'CN-SAMPLE-1001',
            'city' => 'Dhaka',
            'customer' => [
                'id' => 'cust_sample_1',
                'name' => 'Sample Buyer',
                'phone' => '01700000001',
            ],
            'employee' => ['name' => 'Karim'],
            'items' => [
                [
                    'product_id' => 'prod_sample_1',
                    'sku' => 'SMP-POLO-01',
                    'name' => 'Sample Cotton Polo',
                    'quantity' => 2,
                    'unit_price' => 550,
                ],
            ],
        ],
    ],
];
$or1 = lyp_signed_post($baseUrl, '/api/v1/connector/orders', $ordersPayload, $keyId, $secret);
echo "Orders (1st) HTTP {$or1['status']}: {$or1['body']}\n";
$or2 = lyp_signed_post($baseUrl, '/api/v1/connector/orders', $ordersPayload, $keyId, $secret);
echo "Orders (retry) HTTP {$or2['status']}: {$or2['body']}\n";
