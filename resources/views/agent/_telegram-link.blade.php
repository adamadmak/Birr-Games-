{{-- Agent Telegram alerts: linked, code waiting, or not linked. Shared by the Dashboard and Profile. --}}
@php
    try {
        $telegramBot = app(\App\Services\TelegramBotService::class)->botUsername();
    } catch (\Throwable) {
        $telegramBot = '';
    }
@endphp
<div class="agent-telegram-section">
    @if($agent->telegram_linked_at)
        <p class="agent-telegram-status is-linked"><svg class="ag-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11.5 14.5 16 9.5"/></svg> Your Telegram account is linked. You receive instant push notifications when a deposit or withdrawal needs attention.</p>
        <form method="POST" action="{{ route('agent.telegram.unlink') }}" data-confirm="Unlink your Telegram account? You will no longer receive notifications." >
            @csrf
            @method('DELETE')
            <button class="agent-button red" type="submit"><svg class="ag-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 0 1 4.9 6"/><line x1="2" y1="2" x2="22" y2="22"/></svg> Unlink Telegram</button>
        </form>
    @elseif($agent->telegram_link_code)
        <p class="agent-telegram-status is-pending"><svg class="ag-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> Your link code is ready. Open the Vamoseone bot on Telegram and send this code:</p>
        <div class="agent-telegram-code">/link {{ $agent->telegram_link_code }}</div>
        <p class="agent-telegram-help">The code expires after you link it or generate a new one.</p>
        @if($telegramBot !== '')
            <a class="agent-button teal" href="https://t.me/{{ $telegramBot }}" target="_blank" rel="noopener">Open the bot on Telegram</a>
        @endif
    @else
        <p class="agent-telegram-status is-unlinked"><svg class="ag-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8.7 3.5A6 6 0 0 1 18 8c0 2.5.5 4.3 1.3 5.5"/><path d="M6 8c0 4-2 5-2 7h13"/><path d="M10.3 21a2 2 0 0 0 3.4 0"/><line x1="2" y1="2" x2="22" y2="22"/></svg> Link your Telegram account to receive instant push notifications when a deposit needs approval or a withdrawal is assigned to you.</p>
        <form method="POST" action="{{ route('agent.telegram.generate-code') }}">
            @csrf
            <button class="agent-button primary" type="submit"><svg class="ag-ico" viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.8 4.3 2.9 11.6c-.9.35-.87 1.63.04 1.94l4.6 1.55 1.77 5.4c.24.73 1.17.93 1.69.36l2.6-2.83 4.63 3.4c.7.5 1.68.12 1.85-.72l3.2-15.1c.2-.93-.72-1.7-1.6-1.3Z"/><path d="m7.6 15.1 10.6-7.7-8.9 8.7"/></svg> Generate Telegram Link Code</button>
        </form>
    @endif
</div>
