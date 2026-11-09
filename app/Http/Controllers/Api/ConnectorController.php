<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Website;
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
}
