<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiActionLog;
use App\Models\Chatbot;
use App\Models\ChatbotLead;
use App\Models\Order;
use App\Services\Chat\Responder;
use App\Services\Chat\WidgetTokenService;
use App\Services\Security\SecurityEventService;
use App\Services\Sync\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class WidgetController extends Controller
{
    public function __construct(
        private readonly WidgetTokenService $tokens,
        private readonly Responder $responder,
        private readonly SecurityEventService $security
    ) {
    }

    /**
     * Mint a short-lived, Origin/Referer domain-restricted widget token.
     */
    public function token(Request $request, string $publicKey): JsonResponse
    {
        $chatbot = Chatbot::withoutGlobalScopes()
            ->where('public_key', $publicKey)
            ->first();

        if ($chatbot === null) {
            return response()->json([
                'ok' => false,
                'error' => 'chatbot_not_found',
            ], 404);
        }

        $request->attributes->set('widget_chatbot', $chatbot);

        if (! $chatbot->enabled) {
            $this->security->record(
                'widget_auth_failed',
                'low',
                (int) $chatbot->tenant_id,
                (int) $chatbot->website_id,
                $request,
                ['reason' => 'chatbot_disabled']
            );

            return response()->json([
                'ok' => false,
                'error' => 'chatbot_disabled',
            ], 403);
        }

        $requestDomain = $this->tokens->extractRequestDomain($request);
        if ($requestDomain === null || ! hash_equals((string) $chatbot->widget_domain, $requestDomain)) {
            $this->security->record(
                'widget_auth_failed',
                'medium',
                (int) $chatbot->tenant_id,
                (int) $chatbot->website_id,
                $request,
                ['reason' => 'domain_mismatch']
            );

            if ($this->security->shouldThrottle((int) $chatbot->tenant_id, 'widget_auth_failed', $request)) {
                $request->attributes->set('_ly_rate_limit_recorded', true);

                return response()->json([
                    'ok' => false,
                    'error' => 'rate_limited',
                ], 429);
            }

            return response()->json([
                'ok' => false,
                'error' => 'domain_mismatch',
            ], 403);
        }

        $issued = $this->tokens->issue($chatbot);

        return response()->json([
            'ok' => true,
            'token' => $issued['token'],
            'expires_at' => $issued['expires_at'],
            'ttl_seconds' => $issued['ttl_seconds'],
            'domain' => $issued['domain'],
        ]);
    }

    /**
     * Answer a customer message strictly from the verified chatbot's tenant KB (or trigger handoff).
     */
    public function ask(Request $request): JsonResponse
    {
        $chatbot = $this->authenticateWidget($request);
        if ($chatbot instanceof JsonResponse) {
            return $chatbot;
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:'.Responder::MAX_MESSAGE_CHARS],
            'session_token' => ['nullable', 'string', 'max:128'],
        ]);

        if (trim($validated['message']) === '') {
            return response()->json([
                'ok' => false,
                'error' => 'empty_message',
            ], 422);
        }

        $result = $this->responder->reply(
            $chatbot,
            $validated['message'],
            $validated['session_token'] ?? null
        );

        return response()->json(array_merge(['ok' => true], $result));
    }

    /**
     * Two-factor order-status lookup requiring BOTH order_ref and matching customer contact hash.
     * NEVER creates or modifies orders.
     */
    public function orderStatus(Request $request): JsonResponse
    {
        $chatbot = $this->authenticateWidget($request);
        if ($chatbot instanceof JsonResponse) {
            return $chatbot;
        }

        $validated = $request->validate([
            'order_ref' => ['required', 'string', 'min:1', 'max:100'],
            'contact' => ['required', 'string', 'min:5', 'max:150'],
            'session_token' => ['nullable', 'string', 'max:128'],
        ]);

        $tenantId = (int) $chatbot->tenant_id;
        $orderRef = trim($validated['order_ref']);
        $contact = trim($validated['contact']);

        $contactKey = str_contains($contact, '@')
            ? SyncService::customerKeyFor($tenantId, null, $contact)
            : SyncService::customerKeyFor($tenantId, $contact, null);

        $order = Order::withoutGlobalScopes()
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('tenant_id', $tenantId)
            ->where('website_id', (int) $chatbot->website_id)
            ->where(function ($q) use ($orderRef): void {
                $q->where('external_id', $orderRef)->orWhere('consignment_id', $orderRef);
            })
            ->first();

        $verified = $order !== null
            && $order->customer !== null
            && $contactKey !== null
            && hash_equals((string) $order->customer->customer_key, $contactKey);

        $conversationId = null;
        if (! empty($validated['session_token'])) {
            $conv = $this->responder->resolveConversation($chatbot, $validated['session_token']);
            $conversationId = $conv['conversation']->id;
        }

        $log = new AiActionLog();
        $log->forceFill([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'action' => 'order_status_lookup',
            'outcome' => $verified ? 'verified' : 'not_verified',
            'created_at' => now(),
        ]);
        $log->save();

        if (! $verified) {
            return response()->json([
                'ok' => false,
                'error' => 'order_verification_failed',
                'message' => 'Could not verify an order matching those details.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'order_ref' => (string) $order->external_id,
            'status' => (string) $order->status,
            'total' => (string) $order->total,
            'currency' => (string) $order->currency,
            'courier' => $order->courier,
            'ordered_at' => $order->ordered_at?->toIso8601String(),
        ]);
    }

    /**
     * Capture a follow-up lead ONLY when explicit consent is accepted.
     */
    public function lead(Request $request): JsonResponse
    {
        $chatbot = $this->authenticateWidget($request);
        if ($chatbot instanceof JsonResponse) {
            return $chatbot;
        }

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'contact' => ['required', 'string', 'min:5', 'max:150'],
            'consent' => ['required', 'accepted'],
            'session_token' => ['nullable', 'string', 'max:128'],
        ]);

        $tenantId = (int) $chatbot->tenant_id;
        $rawContact = trim($validated['contact']);
        $contactHash = (str_contains($rawContact, '@')
            ? SyncService::customerKeyFor($tenantId, null, $rawContact)
            : SyncService::customerKeyFor($tenantId, $rawContact, null))
            ?? hash_hmac('sha256', "t{$tenantId}|raw:".mb_strtolower($rawContact, 'UTF-8'), (string) config('app.key'));

        $conversationId = null;
        if (! empty($validated['session_token'])) {
            $conv = $this->responder->resolveConversation($chatbot, $validated['session_token']);
            $conversationId = $conv['conversation']->id;
        }

        $now = now();
        $lead = new ChatbotLead();
        $lead->forceFill([
            'tenant_id' => $tenantId,
            'chatbot_id' => $chatbot->id,
            'conversation_id' => $conversationId,
            'name' => isset($validated['name']) && trim($validated['name']) !== ''
                ? mb_substr(trim($validated['name']), 0, 150, 'UTF-8')
                : null,
            'contact_hash' => $contactHash,
            'contact_enc' => Crypt::encryptString($rawContact),
            'consent_at' => $now,
            'created_at' => $now,
        ]);
        $lead->save();

        $log = new AiActionLog();
        $log->forceFill([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'action' => 'lead_captured',
            'outcome' => 'consented',
            'created_at' => $now,
        ]);
        $log->save();

        return response()->json([
            'ok' => true,
            'lead_id' => (int) $lead->id,
            'consent_at' => $now->toIso8601String(),
        ], 201);
    }

    private function authenticateWidget(Request $request): Chatbot|JsonResponse
    {
        $verified = $this->tokens->verifyRequest($request);
        if ($verified['chatbot'] instanceof Chatbot) {
            $request->attributes->set('widget_chatbot', $verified['chatbot']);
        }

        if ($verified['ok'] && $verified['chatbot'] instanceof Chatbot) {
            return $verified['chatbot'];
        }

        $this->security->record(
            'widget_auth_failed',
            'medium',
            $verified['tenant_id'],
            $verified['website_id'],
            $request,
            ['reason' => (string) ($verified['error'] ?? 'unauthorized')]
        );

        return response()->json([
            'ok' => false,
            'error' => $verified['error'] ?? 'unauthorized',
        ], $verified['status']);
    }
}
