@extends('layouts.admin')
@section('title', 'Broadcast Bot')

@section('content')
<div class="admin-page-heading telegram-heading">
    <div><p>Telegram</p><h1>Broadcast Bot</h1><span>Create reusable campaigns and follow real delivery results.</span></div>
    <div class="telegram-heading-actions">
        <a href="https://t.me/{{ $botUsername }}" target="_blank" rel="noopener"><i class="fa-brands fa-telegram"></i> &#64;{{ $botUsername }}</a>
    </div>
</div>

<section class="telegram-connection-card">
    <div class="telegram-connection-icon"><i class="fa-brands fa-telegram"></i></div>
    <div>
        <small>Bot connection</small>
        <strong>{{ $botConfigured ? 'Bot token configured' : 'Token configuration required' }}</strong>
        <span>Webhook subscriptions use /start and /stop. Tokens entered here are verified, encrypted at rest, and never displayed.</span>
        @unless($botConfigured)
            <span>Runtime sources: config {{ $botConfigurationSources['config'] ? 'yes' : 'no' }}, server {{ $botConfigurationSources['server'] ? 'yes' : 'no' }}, environment {{ $botConfigurationSources['environment'] ? 'yes' : 'no' }}, process {{ $botConfigurationSources['process'] ? 'yes' : 'no' }}, encrypted database {{ $botConfigurationSources['database'] ? 'yes' : 'no' }}.</span>
        @endunless
    </div>
    <div class="telegram-subscriber-stats"><strong>{{ number_format($activeSubscribers) }}</strong><span>active</span><small>{{ number_format($totalSubscribers) }} total subscribers</small></div>
    @if($botConfigured)
        <form method="POST" action="{{ route('admin.telegram.webhook.connect') }}">@csrf
            <button type="submit"><i class="fa-solid fa-link"></i> Connect webhook</button>
        </form>
    @else
        <form class="telegram-token-form" method="POST" action="{{ route('admin.telegram.connection.configure') }}">@csrf
            <label for="telegram-bot-token">BotFather token</label>
            <input id="telegram-bot-token" type="password" name="bot_token" required maxlength="255" autocomplete="new-password" placeholder="Paste token securely">
            <button type="submit"><i class="fa-solid fa-shield-halved"></i> Verify & connect</button>
        </form>
    @endif
</section>

<nav class="telegram-tabs" aria-label="Broadcast bot sections">
    <a class="{{ $tab === 'templates' ? 'active' : '' }}" href="{{ route('admin.telegram.index', ['tab' => 'templates']) }}">Template List</a>
    <a class="{{ $tab === 'broadcasts' ? 'active' : '' }}" href="{{ route('admin.telegram.index', ['tab' => 'broadcasts']) }}">Broadcast List</a>
</nav>

@if($tab === 'templates')
    <div class="telegram-toolbar"><div><h2>Template List</h2><p>View and manage templates</p></div><a href="{{ route('admin.telegram.templates.create') }}"><i class="fa-solid fa-plus"></i> Add New</a></div>
    <section class="telegram-table-card">
        <div class="telegram-table-scroll"><table class="telegram-table template-table">
            <thead><tr><th>#</th><th>Name</th><th>Image</th><th>Caption</th><th>Buttons</th><th>Created At</th><th>Updated At</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($templates as $template)
                <tr>
                    <td>{{ $templates->firstItem() + $loop->index }}</td>
                    <td><strong>{{ $template->name }}</strong></td>
                    <td>@if($template->image_source)<img class="telegram-template-thumb" src="{{ $template->image_source }}" alt="{{ $template->name }}">@else<span class="telegram-no-image">—</span>@endif</td>
                    <td><span class="telegram-caption-preview" title="{{ $template->caption }}">{{ Str::limit(strip_tags($template->caption), 58) }}</span></td>
                    <td>{{ count($template->buttons ?? []) }} button(s)</td>
                    <td>{{ $template->created_at->format('n/j/Y') }}</td>
                    <td>{{ $template->updated_at->format('n/j/Y') }}</td>
                    <td><div class="telegram-row-actions"><a href="{{ route('admin.telegram.templates.edit', $template) }}" title="Edit"><i class="fa-regular fa-pen-to-square"></i></a><form method="POST" action="{{ route('admin.telegram.templates.destroy', $template) }}" data-confirm="Delete this template? Existing broadcasts keep their snapshot." >@csrf @method('DELETE')<button title="Delete"><i class="fa-regular fa-trash-can"></i></button></form></div></td>
                </tr>
            @empty
                <tr><td colspan="8"><div class="telegram-empty"><i class="fa-regular fa-paper-plane"></i><h3>No template found</h3><p>Create the first reusable Telegram message.</p></div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('admin.telegram.partials.pagination', ['paginator' => $templates, 'pageName' => 'template_page'])
    </section>
@else
    <div class="telegram-toolbar"><div><h2>Broadcast List</h2><p>View campaigns and database-backed delivery progress</p></div><a href="{{ route('admin.telegram.broadcasts.create') }}"><i class="fa-solid fa-plus"></i> Add New</a></div>
    <section class="telegram-table-card">
        <div class="telegram-table-scroll"><table class="telegram-table broadcast-table">
            <thead><tr><th>#</th><th>Name</th><th>Template</th><th>Status</th><th>Total Recipients</th><th>Sent</th><th>Failed</th><th>Progress</th><th>Scheduled At</th><th>Created At</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse($broadcasts as $broadcast)
                <tr>
                    <td>{{ $broadcasts->firstItem() + $loop->index }}</td>
                    <td><strong>{{ $broadcast->name }}</strong></td>
                    <td>{{ $broadcast->template?->name ?? 'Deleted template' }}</td>
                    <td><span class="telegram-status status-{{ $broadcast->status }}">{{ str($broadcast->status)->headline() }}</span></td>
                    <td>{{ number_format($broadcast->total_recipients) }}</td><td>{{ number_format($broadcast->sent_count) }}</td><td>{{ number_format($broadcast->failed_count) }}</td>
                    <td><div class="telegram-progress"><span style="width:{{ $broadcast->progress }}%"></span></div><small>{{ $broadcast->progress }}%</small></td>
                    <td>{{ $broadcast->scheduled_at?->format('n/j/Y g:i A') ?? '—' }}</td><td>{{ $broadcast->created_at->format('n/j/Y') }}</td>
                    <td><div class="telegram-row-actions"><button type="button" data-member-modal-open="telegram-broadcast-{{ $broadcast->id }}">Detail</button><a href="{{ route('admin.telegram.broadcasts.edit', $broadcast) }}" title="Edit"><i class="fa-regular fa-pen-to-square"></i></a>@if(in_array($broadcast->status, ['draft', 'scheduled', 'queued', 'sending']))<form method="POST" action="{{ route('admin.telegram.broadcasts.send', $broadcast) }}" data-confirm="Send this broadcast now to all pending Telegram subscribers?" >@csrf<button class="send">{{ in_array($broadcast->status, ['queued', 'sending']) ? 'Send now' : 'Send' }}</button></form>@endif @if($broadcast->failed_count>0)<form method="POST" action="{{ route('admin.telegram.broadcasts.retry', $broadcast) }}" data-confirm="Retry only the failed Telegram deliveries?" >@csrf<button class="send">Retry {{ $broadcast->failed_count }}</button></form>@endif<form method="POST" action="{{ route('admin.telegram.broadcasts.destroy', $broadcast) }}" data-confirm="Delete this broadcast? Any messages delivered to Telegram subscribers will also be deleted from their chats." >@csrf @method('DELETE')<button title="Delete"><i class="fa-regular fa-trash-can"></i></button></form></div></td>
                </tr>
            @empty
                <tr><td colspan="11"><div class="telegram-empty"><i class="fa-solid fa-tower-broadcast"></i><h3>No broadcasts found</h3><p>Create a draft from one of your templates.</p></div></td></tr>
            @endforelse
            </tbody>
        </table></div>
        @include('admin.telegram.partials.pagination', ['paginator' => $broadcasts, 'pageName' => 'broadcast_page'])
    </section>

    @foreach($broadcasts as $broadcast)
    <dialog id="telegram-broadcast-{{ $broadcast->id }}" class="reference-member-modal telegram-detail-modal" data-member-modal>
        <header><div><small>Broadcast detail</small><h2>{{ $broadcast->name }}</h2><p>Created {{ $broadcast->created_at->format('M j, Y g:i A') }}</p></div><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
        <div class="telegram-detail-grid">
            <article><span>Status</span><strong class="telegram-status status-{{ $broadcast->status }}">{{ str($broadcast->status)->headline() }}</strong></article>
            <article><span>Template</span><strong>{{ $broadcast->template?->name ?? 'Deleted template' }}</strong></article>
            <article><span>Total recipients</span><strong>{{ number_format($broadcast->total_recipients) }}</strong></article>
            <article><span>Sent</span><strong>{{ number_format($broadcast->sent_count) }}</strong></article>
            <article><span>Failed</span><strong>{{ number_format($broadcast->failed_count) }}</strong></article>
            <article><span>Progress</span><strong>{{ $broadcast->progress }}%</strong></article>
            <article class="wide"><span>Caption</span><p>{!! nl2br(e($broadcast->caption)) !!}</p></article>
            @if($broadcast->image_source)<article class="wide"><span>Image</span><img src="{{ $broadcast->image_source }}" alt="Broadcast image"></article>@endif
            <article class="wide"><span>Buttons</span>@forelse($broadcast->buttons ?? [] as $button)<p><strong>{{ $button['text'] }}</strong> · {{ str($button['type'])->headline() }}: {{ $button['value'] }}</p>@empty<p>—</p>@endforelse</article>
            <article><span>Scheduled at</span><strong>{{ $broadcast->scheduled_at?->format('M j, Y g:i A') ?? '—' }}</strong></article>
            <article><span>Started at</span><strong>{{ $broadcast->started_at?->format('M j, Y g:i A') ?? '—' }}</strong></article>
            <article><span>Completed at</span><strong>{{ $broadcast->completed_at?->format('M j, Y g:i A') ?? '—' }}</strong></article>
        </div>
        <footer>
            <a href="{{ route('admin.telegram.broadcasts.edit', $broadcast) }}" style="padding: 8px 16px; background: var(--info); color: #fff; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px;"><i class="fa-regular fa-pen-to-square"></i> Edit Broadcast</a>
            <form method="POST" action="{{ route('admin.telegram.broadcasts.destroy', $broadcast) }}" data-confirm="Delete this broadcast? Any messages delivered to Telegram subscribers will also be deleted from their chats." style="display:inline;">@csrf @method('DELETE')<button type="submit" style="background: var(--danger); color: #fff; border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;"><i class="fa-regular fa-trash-can"></i> Delete Broadcast</button></form>
            <button type="button" data-member-modal-close>Close</button>
        </footer>
    </dialog>
    @endforeach
@endif
@endsection
