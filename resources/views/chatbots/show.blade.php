@extends('layouts.app')

@section('title', $chatbot->name . ' - Chatbot - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">{{ $chatbot->name }}</h1>
        <p class="page-subtitle">
            Store: {{ $chatbot->website?->name ?? '—' }} &middot; Allowed Origin: <span class="mono">{{ $chatbot->widget_domain }}</span>
        </p>
    </div>
    <div class="page-actions">
        <a href="{{ route('chatbots.index') }}" class="btn btn--secondary">All chatbots</a>
    </div>
</div>

@if(session('issued_widget_token'))
    @php $minted = session('issued_widget_token'); @endphp
    <div class="alert alert--ok" role="status">
        <strong>Short-lived widget token minted (expires at {{ date('Y-m-d H:i:s', (int) $minted['expires_at']) }}, domain {{ $minted['domain'] }}):</strong>
        <div class="mono-box mt-sm">{{ $minted['token'] }}</div>
    </div>
@endif

@if(session('preview_answer'))
    @php $preview = session('preview_answer'); @endphp
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Preview Result</h2>
            @if(!empty($preview['grounded']))
                <span class="badge badge--ok">Grounded ({{ $preview['source_doc_title'] ?? 'KB' }})</span>
            @else
                <span class="badge badge--warn">Human Handoff Triggered</span>
            @endif
        </div>
        <p><strong>Question:</strong> {{ session('preview_question') }}</p>
        <div class="baseline-box">{{ $preview['answer'] }}</div>
    </div>
@endif

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Widget &amp; Handoff Configuration</h2>
            @if($chatbot->enabled)
                <span class="badge badge--ok">Enabled</span>
            @else
                <span class="badge badge--warn">Disabled</span>
            @endif
        </div>

        <div class="kv-grid mb-sm">
            <div>
                <div class="kv-item-label">Public Key</div>
                <div class="kv-item-value mono">{{ $chatbot->public_key }}</div>
            </div>
            <div>
                <div class="kv-item-label">Token Endpoint</div>
                <div class="kv-item-value mono">POST /api/v1/widget/{{ $chatbot->public_key }}/token</div>
            </div>
        </div>

        @if(auth()->user()->canManage())
            <form method="POST" action="{{ route('chatbots.update', $chatbot) }}" class="mb-sm">
                @csrf
                <div class="field">
                    <label for="widget_domain">Allowed Widget Domain</label>
                    <input id="widget_domain" type="text" name="widget_domain" class="input" value="{{ old('widget_domain', $chatbot->widget_domain) }}" maxlength="253" required>
                </div>

                <div class="field">
                    <label for="handoff_text">Handoff Message</label>
                    <textarea id="handoff_text" name="handoff_text" class="textarea" maxlength="1000" required>{{ old('handoff_text', $chatbot->handoff_text) }}</textarea>
                </div>

                <label class="checkbox-row">
                    <input type="checkbox" name="enabled" value="1" @checked(old('enabled', $chatbot->enabled))>
                    <span>Widget enabled (disable immediately blocks widget endpoints)</span>
                </label>

                <button type="submit" class="btn btn--primary btn--sm">Save settings</button>
            </form>

            <form method="POST" action="{{ route('chatbots.token', $chatbot) }}">
                @csrf
                <button type="submit" class="btn btn--secondary btn--sm">Mint 15-min preview token</button>
            </form>
        @endif
    </div>

    @if(auth()->user()->canManage())
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Owner Preview / Ask Question</h2>
            </div>
            <p class="help-text">Test grounded Bangla, Banglish, or English replies vs. unknown/sensitive handoff safely from the dashboard.</p>
            <form method="POST" action="{{ route('chatbots.test-ask', $chatbot) }}">
                @csrf
                <div class="field">
                    <label for="preview-question">Customer Question (max 800 chars)</label>
                    <textarea id="preview-question" name="question" class="textarea" maxlength="800" required placeholder="e.g. ডেলিভারি চার্জ কত? / Inside Dhaka return policy? / Place an order for me">{{ old('question') }}</textarea>
                </div>
                <button type="submit" class="btn btn--primary">Test grounded reply</button>
            </form>
        </div>
    @endif
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Knowledge Base Documents ({{ $documents->count() }})</h2>
        </div>

        @if(auth()->user()->canManage())
            <form method="POST" action="{{ route('chatbots.documents.store', $chatbot) }}" enctype="multipart/form-data" class="mb-sm">
                @csrf
                <div class="field">
                    <label for="doc-title">Document Title</label>
                    <input id="doc-title" type="text" name="title" class="input" value="{{ old('title') }}" maxlength="200" placeholder="Delivery Charge &amp; Return Policy" required>
                </div>

                <div class="field">
                    <label for="doc-body">FAQ / Policy Text (No customer PII allowed)</label>
                    <textarea id="doc-body" name="body" class="textarea" maxlength="50000" placeholder="Inside Dhaka delivery charge is 60 Tk within 24-48 hours. Cash on Delivery supported.">{{ old('body') }}</textarea>
                </div>

                <div class="field">
                    <label for="doc-file">Or Upload Plain Text File (.txt or .md, max 512 KB)</label>
                    <input id="doc-file" type="file" name="file" class="input" accept=".txt,.md,text/plain,text/markdown">
                </div>

                <button type="submit" class="btn btn--primary btn--sm">Save KB document</button>
            </form>
        @endif

        @if($documents->isEmpty())
            <div class="empty-state">No knowledge documents uploaded yet. Every question will trigger human handoff until you add policy/FAQ entries.</div>
        @else
            <div class="finding-list">
                @foreach($documents as $doc)
                    <div class="finding-card">
                        <div class="finding-card-head">
                            <div>
                                <div class="finding-card-title">{{ $doc->title }}</div>
                                <span class="badge badge--neutral">{{ $doc->source }}</span>
                            </div>
                            @if(auth()->user()->canManage())
                                <form method="POST" action="{{ route('chatbots.documents.destroy', [$chatbot, $doc]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn--danger btn--sm">Delete</button>
                                </form>
                            @endif
                        </div>
                        <div class="help-text">{{ $doc->body }}</div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Consented Leads ({{ $leads->count() }})</h2>
        </div>
        @if($leads->isEmpty())
            <div class="empty-state">No follow-up leads captured yet. Explicit consent acceptance is required before saving any lead.</div>
        @else
            <div class="table-wrap mb-sm">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Masked Contact</th>
                            <th>Consent Recorded</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($leads as $lead)
                            <tr>
                                <td>{{ $lead['name'] }}</td>
                                <td class="mono">{{ $lead['masked_contact'] }}</td>
                                <td class="mono">{{ $lead['consent_at']?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="panel-head mt-sm">
            <h2 class="panel-title">Recent Conversations</h2>
        </div>
        @if($conversations->isEmpty())
            <div class="empty-state">No widget or preview conversations recorded yet.</div>
        @else
            <div class="finding-list">
                @foreach($conversations as $conv)
                    <div class="finding-card">
                        <div class="finding-meta">
                            <span class="badge {{ $conv->status === 'handoff' ? 'badge--warn' : 'badge--ok' }}">{{ ucfirst($conv->status) }}</span>
                            <span class="mono muted">Conv #{{ $conv->id }}</span>
                            <span class="muted">{{ $conv->last_message_at?->format('Y-m-d H:i:s') }}</span>
                        </div>
                        @foreach($conv->messages as $msg)
                            <div class="mt-sm">
                                <strong>{{ ucfirst($msg->role) }}</strong>
                                @if($msg->role === 'bot')
                                    <span class="badge {{ $msg->grounded ? 'badge--ok' : 'badge--warn' }}">
                                        {{ $msg->grounded ? 'Grounded' : 'Handoff' }}
                                    </span>
                                @endif
                                <div>{{ $msg->body }}</div>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="panel-title">Widget &amp; Action Logs (Recent)</h2>
    </div>
    @if($actionLogs->isEmpty())
        <div class="empty-state">No chatbot or order-lookup action logs recorded yet.</div>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>Outcome</th>
                        <th>Conversation</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($actionLogs as $log)
                        <tr>
                            <td class="mono">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td><span class="badge badge--neutral">{{ $log->action }}</span></td>
                            <td class="mono">{{ $log->outcome }}</td>
                            <td class="mono">{{ $log->conversation_id ? '#'.$log->conversation_id : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
