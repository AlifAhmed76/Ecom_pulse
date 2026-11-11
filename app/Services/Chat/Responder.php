<?php

namespace App\Services\Chat;

use App\Models\AiActionLog;
use App\Models\Chatbot;
use App\Models\ChatMessage;
use App\Models\Conversation;
use Illuminate\Support\Facades\DB;

class Responder
{
    public const MAX_MESSAGE_CHARS = 800;

    /**
     * Keywords indicating sensitive escalation, prompt-injection/exfiltration, or unsupported autonomous actions.
     *
     * @var list<string>
     */
    private const ESCALATION_PHRASES = [
        'human', 'agent', 'representative', 'manager', 'staff', 'support person', 'talk to someone',
        'complaint', 'fraud', 'scam', 'cheat', 'police', 'lawyer', 'legal', 'court', 'consumer rights',
        'double charge', 'charged twice', 'damaged', 'broken', 'defective', 'missing item', 'wrong item', 'wrong product',
        'place order', 'create order', 'order for me', 'book order', 'make order', 'cancel my order',
        'change price', 'give discount', 'apply discount',
        'ignore previous', 'ignore instructions', 'forget rules', 'disregard', 'override rules', 'reveal prompt', 'jailbreak', 'bypass', 'system prompt', 'secret', 'api key', 'password', 'database', 'all tenants',
        'মানুষ', 'ম্যানেজার', 'প্রতিনিধি', 'অভিযোগ', 'প্রতারণা', 'মামলা', 'টাকা কেটে', 'নষ্ট', 'ভাঙা', 'ভুল প্রোডাক্ট',
        'অর্ডার করে দাও', 'অর্ডার করুন আমার হয়ে', 'দাম কমান', 'ডিসকাউন্ট দিন', 'পাসওয়ার্ড',
    ];

    public function __construct(private readonly Retriever $retriever)
    {
    }

    /**
     * Resolve or start a strictly scoped conversation by hashed session token.
     */
    public function resolveConversation(Chatbot $chatbot, ?string $rawSessionToken = null): array
    {
        $tenantId = (int) $chatbot->tenant_id;
        $token = is_string($rawSessionToken) && trim($rawSessionToken) !== ''
            ? mb_substr(trim($rawSessionToken), 0, 128, 'UTF-8')
            : bin2hex(random_bytes(16));

        $hash = hash('sha256', "t{$tenantId}|cb{$chatbot->id}|{$token}");

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('chatbot_id', $chatbot->id)
            ->where('session_token_hash', $hash)
            ->first();

        if ($conversation === null) {
            $conversation = new Conversation();
            $conversation->forceFill([
                'tenant_id' => $tenantId,
                'chatbot_id' => $chatbot->id,
                'session_token_hash' => $hash,
                'status' => 'open',
                'started_at' => now(),
                'last_message_at' => now(),
            ]);
            $conversation->save();
        }

        return [
            'conversation' => $conversation,
            'session_token' => $token,
        ];
    }

    /**
     * Handle a customer chat question strictly grounded in tenant KB documents (or human handoff).
     *
     * @return array{
     *   session_token: string,
     *   conversation_id: int,
     *   conversation_status: string,
     *   answer: string,
     *   grounded: bool,
     *   handoff: bool,
     *   source_doc_id: ?int,
     *   source_doc_title: ?string
     * }
     */
    public function reply(Chatbot $chatbot, string $rawQuestion, ?string $rawSessionToken = null): array
    {
        $question = mb_substr(trim($rawQuestion), 0, self::MAX_MESSAGE_CHARS, 'UTF-8');

        return DB::transaction(function () use ($chatbot, $question, $rawSessionToken): array {
            $tenantId = (int) $chatbot->tenant_id;
            $resolved = $this->resolveConversation($chatbot, $rawSessionToken);
            /** @var Conversation $conversation */
            $conversation = $resolved['conversation'];
            $sessionToken = $resolved['session_token'];
            $now = now();

            $userMsg = new ChatMessage();
            $userMsg->forceFill([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'body' => $question,
                'grounded' => false,
                'source_doc_id' => null,
                'created_at' => $now,
            ]);
            $userMsg->save();

            // Check sensitive / action / escalation triggers BEFORE knowledge lookup
            $escalationReason = $this->detectEscalation($question);
            if ($escalationReason !== null) {
                return $this->handoffResponse($chatbot, $conversation, $sessionToken, $escalationReason);
            }

            // Retrieve ONLY from this tenant + chatbot knowledge documents
            $match = $this->retriever->search($tenantId, (int) $chatbot->id, $question);
            if ($match === null) {
                return $this->handoffResponse($chatbot, $conversation, $sessionToken, 'ungrounded_unknown');
            }

            $doc = $match['document'];
            $answer = trim($match['snippet'])."\n[Source: {$doc->title}]";

            $botMsg = new ChatMessage();
            $botMsg->forceFill([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'role' => 'bot',
                'body' => $answer,
                'grounded' => true,
                'source_doc_id' => $doc->id,
                'created_at' => $now,
            ]);
            $botMsg->save();

            $conversation->forceFill(['last_message_at' => $now])->save();

            $log = new AiActionLog();
            $log->forceFill([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->id,
                'action' => 'kb_answer',
                'outcome' => "doc:{$doc->id}",
                'created_at' => $now,
            ]);
            $log->save();

            return [
                'session_token' => $sessionToken,
                'conversation_id' => (int) $conversation->id,
                'conversation_status' => (string) $conversation->status,
                'answer' => $answer,
                'grounded' => true,
                'handoff' => false,
                'source_doc_id' => (int) $doc->id,
                'source_doc_title' => (string) $doc->title,
            ];
        });
    }

    private function detectEscalation(string $question): ?string
    {
        $lower = mb_strtolower($question, 'UTF-8');
        foreach (self::ESCALATION_PHRASES as $phrase) {
            if (str_contains($lower, $phrase)) {
                return 'sensitive_or_action_request';
            }
        }

        return null;
    }

    /**
     * @return array{
     *   session_token: string,
     *   conversation_id: int,
     *   conversation_status: string,
     *   answer: string,
     *   grounded: bool,
     *   handoff: bool,
     *   source_doc_id: ?int,
     *   source_doc_title: ?string
     * }
     */
    private function handoffResponse(
        Chatbot $chatbot,
        Conversation $conversation,
        string $sessionToken,
        string $reason
    ): array {
        $now = now();
        $handoffText = trim((string) $chatbot->handoff_text);
        if ($handoffText === '') {
            $handoffText = 'This question needs our human team. Share your contact details with consent below and we will follow up.';
        }

        $conversation->forceFill([
            'status' => 'handoff',
            'last_message_at' => $now,
        ])->save();

        $botMsg = new ChatMessage();
        $botMsg->forceFill([
            'tenant_id' => (int) $chatbot->tenant_id,
            'conversation_id' => $conversation->id,
            'role' => 'bot',
            'body' => $handoffText,
            'grounded' => false,
            'source_doc_id' => null,
            'created_at' => $now,
        ]);
        $botMsg->save();

        $log = new AiActionLog();
        $log->forceFill([
            'tenant_id' => (int) $chatbot->tenant_id,
            'conversation_id' => $conversation->id,
            'action' => 'handoff_triggered',
            'outcome' => $reason,
            'created_at' => $now,
        ]);
        $log->save();

        return [
            'session_token' => $sessionToken,
            'conversation_id' => (int) $conversation->id,
            'conversation_status' => 'handoff',
            'answer' => $handoffText,
            'grounded' => false,
            'handoff' => true,
            'source_doc_id' => null,
            'source_doc_title' => null,
        ];
    }
}
