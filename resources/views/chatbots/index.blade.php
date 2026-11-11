@extends('layouts.app')

@section('title', 'Chatbots - Last Year Project')

@section('content')
<div class="page-header">
    <div>
        <h1 class="page-title">Grounded Store Chatbots</h1>
        <p class="page-subtitle">Strict tenant-scoped FAQ &amp; policy chatbots with automatic human handoff and two-factor order lookup.</p>
    </div>
</div>

<div class="alert alert--info">
    <strong>Grounding Guarantee:</strong> Chatbots answer ONLY from knowledge documents uploaded to your store (Bangla, Banglish, or English). Unknown, sensitive, or order-creation requests automatically trigger human handoff. Never enter customer phone numbers, emails, or personal data in knowledge documents.
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Configured Chatbots</h2>
        </div>

        @if($chatbots->isEmpty())
            <div class="empty-state">
                <p>No chatbots configured for this workspace yet.</p>
                @if($websites->isEmpty())
                    <p>Register a website under <a href="{{ route('websites.index') }}">Websites</a> first.</p>
                @endif
            </div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Chatbot</th>
                            <th>Store</th>
                            <th>Allowed Origin</th>
                            <th>Status</th>
                            <th class="num">KB Docs</th>
                            <th class="num">Chats</th>
                            <th class="num">Leads</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($chatbots as $bot)
                            <tr>
                                <td>
                                    <a href="{{ route('chatbots.show', $bot) }}"><strong>{{ $bot->name }}</strong></a>
                                    <div class="help-text mono">{{ $bot->public_key }}</div>
                                </td>
                                <td>{{ $bot->website?->name ?? '—' }}</td>
                                <td class="mono">{{ $bot->widget_domain }}</td>
                                <td>
                                    @if($bot->enabled)
                                        <span class="badge badge--ok">Enabled</span>
                                    @else
                                        <span class="badge badge--warn">Disabled</span>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($bot->documents_count) }}</td>
                                <td class="num">{{ number_format($bot->conversations_count) }}</td>
                                <td class="num">{{ number_format($bot->leads_count) }}</td>
                                <td>
                                    <a href="{{ route('chatbots.show', $bot) }}" class="btn btn--secondary btn--sm">Manage</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    @if(auth()->user()->canManage())
        <div class="panel">
            <div class="panel-head">
                <h2 class="panel-title">Create Store Chatbot</h2>
            </div>

            @if($websites->isEmpty())
                <div class="empty-state">
                    <p>Create at least one website first to attach a chatbot widget.</p>
                    <a href="{{ route('websites.create') }}" class="btn btn--primary btn--sm">Add website</a>
                </div>
            @else
                <form method="POST" action="{{ route('chatbots.store') }}">
                    @csrf
                    <div class="field">
                        <label for="website_id">Store Website</label>
                        <select id="website_id" name="website_id" class="select" required>
                            @foreach($websites as $site)
                                <option value="{{ $site->id }}" @selected(old('website_id') == $site->id)>
                                    {{ $site->name }} ({{ $site->domain ?? 'file-only' }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="field">
                        <label for="name">Chatbot Name</label>
                        <input id="name" type="text" name="name" class="input" value="{{ old('name', 'Store Support Assistant') }}" maxlength="120" required>
                    </div>

                    <div class="field">
                        <label for="widget_domain">Allowed Widget Domain (Origin / Referer check)</label>
                        <input id="widget_domain" type="text" name="widget_domain" class="input" value="{{ old('widget_domain', $websites->first()?->domain) }}" placeholder="shop.example.com" maxlength="253" required>
                        <p class="help-text">Short-lived widget tokens strictly verify Origin/Referer headers against this normalized host.</p>
                    </div>

                    <div class="field">
                        <label for="handoff_text">Human Handoff Message</label>
                        <textarea id="handoff_text" name="handoff_text" class="textarea" maxlength="1000">{{ old('handoff_text', 'This question needs our human team. Please share your phone or email with consent below so our staff can follow up.') }}</textarea>
                    </div>

                    <button type="submit" class="btn btn--primary">Create chatbot</button>
                </form>
            @endif
        </div>
    @endif
</div>
@endsection
