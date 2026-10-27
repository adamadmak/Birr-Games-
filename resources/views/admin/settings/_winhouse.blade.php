{{-- WinHouse sportsbook keys, on the System tab. $winhouse comes from
     SettingController::winhouseState(). Secrets are write-only: the boxes
     render empty and a blank box keeps the stored value. --}}
@php
    $sourceLabel = fn (string $source) => match ($source) {
        'settings' => 'saved here',
        'config', 'env' => 'from the server environment',
        'built-in' => 'built into this deployment for its original domain',
        default => 'not set',
    };
@endphp
<div class="configuration-form-section divided">
    <h3>WinHouse sportsbook</h3>
    <p>The sportsbook on the front page is WinHouse's book in an iframe, playing on this wallet. The keys come from their portal (iframe.winhouse.bet/portal → Manage Account). Paste them here, and give WinHouse the wallet address below.</p>
</div>
@if ($winhouse['configured'])
    <div class="configuration-form-feedback">
        <strong>Connected.</strong>
        Iframe key {{ $sourceLabel($winhouse['sources']['iframe_key']) }}; wallet API key {{ $sourceLabel($winhouse['sources']['api_key']) }}; callback token {{ $sourceLabel($winhouse['sources']['callback_token']) }}. Mode: <strong>{{ $winhouse['mode'] }}</strong>, {{ $winhouse['currency'] }}.
    </div>
@else
    <div class="configuration-form-feedback">
        <div class="form-errors">
            <strong>The sportsbook is not connected on {{ $winhouse['host'] }}.</strong>
            Missing: {{ implode(', ', $winhouse['missing']) }}. The book loads without a key and every stake is refused until all three are set.
        </div>
    </div>
@endif
<div class="configuration-form-grid three">
    <label>Iframe key
        <input name="winhouse_iframe_key" value="{{ old('winhouse_iframe_key', $winhouse['iframe_key']) }}" placeholder="ifr_…" autocomplete="off">
        <small>Portal → Manage Account → Iframe key. It selects your skin and ships in the page source, so it is not a secret. Rotating it in the portal stops the embed until it is updated here.</small>
    </label>
    <label>Wallet API key
        <input type="password" name="winhouse_api_key" autocomplete="new-password"
               placeholder="{{ $winhouse['api_key_set'] ? 'Saved — type a new one to replace it' : 'Not set' }}">
        <small>Portal → Seamless wallet → Wallet API key. Signs the launch token this site mints and every balance call WinHouse make. {{ $winhouse['api_key_set'] ? 'Saved. Leave blank to keep it.' : 'Required before a player can bet.' }}</small>
    </label>
    <label>Callback token
        <input type="password" name="winhouse_callback_token" autocomplete="new-password"
               placeholder="{{ $winhouse['callback_set'] ? 'Saved — type a new one to replace it' : 'Not set' }}">
        <small>WinHouse send it on every wallet call and this site refuses calls without it. Choose any long random value and type the same one in the portal's Callback token box. {{ $winhouse['callback_set'] ? 'Saved. Leave blank to keep it.' : 'Required.' }}</small>
    </label>
</div>
<div class="configuration-form-grid two">
    <label>Mode
        <select name="winhouse_mode">
            <option value="sandbox" @selected($winhouse['mode'] !== 'live')>Sandbox — no real money</option>
            <option value="live" @selected($winhouse['mode'] === 'live')>Live — real wallet</option>
        </select>
        <small>Sandbox answers every stake with a fixed balance and touches no wallet. Switch to live once the portal shows "Connected · live".</small>
    </label>
    <label>Wallet base URL — give this to WinHouse
        <input value="{{ $winhouse['wallet_base_url'] }}" readonly>
        <small>Portal → Seamless wallet → Wallet base URL. WinHouse call <code>/user-data</code>, <code>/balance-change</code> and <code>/transaction</code> under it.</small>
    </label>
</div>
