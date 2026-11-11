<?php

namespace App\Http\Controllers;

use App\Models\AiActionLog;
use App\Models\AuditLog;
use App\Models\Chatbot;
use App\Models\ChatbotLead;
use App\Models\Conversation;
use App\Models\KnowledgeDocument;
use App\Models\User;
use App\Models\Website;
use App\Services\Chat\Responder;
use App\Services\Chat\WidgetTokenService;
use App\Support\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    public function index(): View
    {
        $chatbots = Chatbot::with('website')
            ->withCount(['documents', 'conversations', 'leads'])
            ->orderByDesc('id')
            ->get();

        $websites = Website::orderBy('name')->get(['id', 'name', 'domain']);

        return view('chatbots.index', compact('chatbots', 'websites'));
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'website_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'widget_domain' => ['required', 'string', 'max:253'],
            'handoff_text' => ['nullable', 'string', 'max:1000'],
        ]);

        $website = Website::findOrFail((int) $validated['website_id']);
        $normalizedDomain = Domain::normalize($validated['widget_domain']);
        if ($normalizedDomain === null) {
            return back()
                ->withErrors(['widget_domain' => 'Enter a valid widget domain such as shop.example.com.'])
                ->withInput();
        }

        $handoff = trim((string) ($validated['handoff_text'] ?? ''));
        if ($handoff === '') {
            $handoff = 'This request needs our human team. Share your contact details with consent below and we will reach out shortly.';
        }

        $chatbot = new Chatbot();
        $chatbot->forceFill([
            'tenant_id' => (int) $user->tenant_id,
            'website_id' => (int) $website->id,
            'name' => trim($validated['name']),
            'enabled' => true,
            'widget_domain' => $normalizedDomain,
            'public_key' => 'cb_'.Str::random(24),
            'handoff_text' => $handoff,
        ]);
        $chatbot->save();

        AuditLog::record('chatbot.created', "Created chatbot #{$chatbot->id} ({$normalizedDomain})");

        return redirect()
            ->route('chatbots.show', $chatbot)
            ->with('ok', 'Chatbot created. Add FAQ/policy knowledge documents below before testing.');
    }

    public function show(Chatbot $chatbot): View
    {
        $chatbot->load('website');

        $documents = KnowledgeDocument::where('chatbot_id', $chatbot->id)
            ->orderByDesc('id')
            ->get();

        $conversations = Conversation::where('chatbot_id', $chatbot->id)
            ->with(['messages' => fn ($q) => $q->orderBy('id')])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->limit(15)
            ->get();

        $leads = ChatbotLead::where('chatbot_id', $chatbot->id)
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(function (ChatbotLead $lead): array {
                $masked = '[encrypted]';
                try {
                    $plain = Crypt::decryptString((string) $lead->contact_enc);
                    $len = mb_strlen($plain, 'UTF-8');
                    $masked = $len <= 4
                        ? str_repeat('*', $len)
                        : mb_substr($plain, 0, 2, 'UTF-8').str_repeat('*', min(6, $len - 4)).mb_substr($plain, -2, null, 'UTF-8');
                } catch (\Throwable) {
                    $masked = '[encrypted]';
                }

                return [
                    'id' => $lead->id,
                    'name' => $lead->name ?: 'Guest',
                    'masked_contact' => $masked,
                    'consent_at' => $lead->consent_at,
                ];
            });

        $conversationIds = $conversations->pluck('id');
        $actionLogs = AiActionLog::query()
            ->when($conversationIds->isNotEmpty(), fn ($q) => $q->whereIn('conversation_id', $conversationIds))
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return view('chatbots.show', compact('chatbot', 'documents', 'conversations', 'leads', 'actionLogs'));
    }

    public function update(Request $request, Chatbot $chatbot): RedirectResponse
    {
        $validated = $request->validate([
            'widget_domain' => ['required', 'string', 'max:253'],
            'handoff_text' => ['required', 'string', 'min:5', 'max:1000'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $normalizedDomain = Domain::normalize($validated['widget_domain']);
        if ($normalizedDomain === null) {
            return back()
                ->withErrors(['widget_domain' => 'Enter a valid widget domain such as shop.example.com.'])
                ->withInput();
        }

        $chatbot->forceFill([
            'widget_domain' => $normalizedDomain,
            'handoff_text' => trim($validated['handoff_text']),
            'enabled' => $request->boolean('enabled'),
        ])->save();

        AuditLog::record('chatbot.updated', "Updated chatbot #{$chatbot->id}");

        return redirect()
            ->route('chatbots.show', $chatbot)
            ->with('ok', 'Chatbot settings saved.');
    }

    public function issueToken(Chatbot $chatbot, WidgetTokenService $tokens): RedirectResponse
    {
        if (! $chatbot->enabled) {
            return back()->with('err', 'Enable the chatbot before minting a widget preview token.');
        }

        $issued = $tokens->issue($chatbot);

        AuditLog::record('chatbot.token_issued', "Minted short-lived widget token for chatbot #{$chatbot->id}");

        return redirect()
            ->route('chatbots.show', $chatbot)
            ->with('ok', 'Short-lived widget preview token minted (valid 15 minutes).')
            ->with('issued_widget_token', $issued);
    }

    public function storeDocument(Request $request, Chatbot $chatbot): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:50000'],
            'file' => ['nullable', 'file', 'max:512'],
        ]);

        $source = 'manual';
        $body = trim((string) ($validated['body'] ?? ''));

        if ($request->hasFile('file')) {
            $uploaded = $request->file('file');
            $ext = strtolower((string) $uploaded->getClientOriginalExtension());
            if (! in_array($ext, ['txt', 'md'], true)) {
                return back()->withErrors(['file' => 'Only .txt and .md knowledge files up to 512 KB are accepted.'])->withInput();
            }

            $disk = Storage::disk('local');
            $storedPath = $uploaded->store("chatbot-tmp/t{$user->tenant_id}", 'local');
            try {
                $rawContent = $disk->get($storedPath);
            } finally {
                if (is_string($storedPath) && $storedPath !== '') {
                    $disk->delete($storedPath);
                }
            }

            if (! is_string($rawContent) || str_contains($rawContent, "\0") || trim($rawContent) === '') {
                return back()->withErrors(['file' => 'Uploaded text file is empty, binary, or unreadable.'])->withInput();
            }
            if (preg_match('/<!DOCTYPE\b/i', $rawContent)) {
                return back()->withErrors(['file' => 'DOCTYPE declarations are rejected.'])->withInput();
            }
            $body = trim(mb_substr($rawContent, 0, 50000, 'UTF-8'));
            $source = 'file';
        }

        if ($body === '') {
            return back()->withErrors(['body' => 'Provide knowledge text or upload a .txt / .md file.'])->withInput();
        }

        if ($this->containsPii($body) || $this->containsPii((string) $validated['title'])) {
            return back()
                ->withErrors(['body' => 'Knowledge documents must not contain phone numbers or email addresses (no customer PII allowed).'])
                ->withInput();
        }

        $doc = new KnowledgeDocument();
        $doc->forceFill([
            'tenant_id' => (int) $user->tenant_id,
            'chatbot_id' => $chatbot->id,
            'title' => trim($validated['title']),
            'body' => $body,
            'source' => $source,
        ]);
        $doc->save();

        AuditLog::record('chatbot.kb_added', "Added KB doc #{$doc->id} to chatbot #{$chatbot->id}");

        return redirect()
            ->route('chatbots.show', $chatbot)
            ->with('ok', 'Knowledge document saved (no PII verified).');
    }

    public function destroyDocument(Chatbot $chatbot, KnowledgeDocument $document): RedirectResponse
    {
        if ((int) $document->chatbot_id !== (int) $chatbot->id) {
            abort(404);
        }

        $docId = $document->id;
        $document->delete();

        AuditLog::record('chatbot.kb_deleted', "Deleted KB doc #{$docId} from chatbot #{$chatbot->id}");

        return redirect()
            ->route('chatbots.show', $chatbot)
            ->with('ok', 'Knowledge document deleted.');
    }

    public function testAsk(Request $request, Chatbot $chatbot, Responder $responder): RedirectResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'min:1', 'max:'.Responder::MAX_MESSAGE_CHARS],
        ]);

        if (trim($validated['question']) === '') {
            return back()->withErrors(['question' => 'Enter a non-empty question to preview.']);
        }

        $result = $responder->reply($chatbot, $validated['question']);

        return redirect()
            ->route('chatbots.show', $chatbot)
            ->with('preview_answer', $result)
            ->with('preview_question', trim($validated['question']));
    }

    private function containsPii(string $text): bool
    {
        if (preg_match('/(?:\+?88[\s-]?)?01[3-9][\s-]?\d{4}[\s-]?\d{4}/', $text) === 1) {
            return true;
        }

        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text) === 1) {
            return true;
        }

        return false;
    }
}
