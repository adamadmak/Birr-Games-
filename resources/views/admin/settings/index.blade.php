@extends('layouts.admin')
<form id="run-migrations-form" method="POST" action="{{ route('admin.settings.migrations.run') }}"
      data-confirm="Apply the waiting schema updates to this database?" >@csrf</form>
{{-- Same reason as above: its own form, reached by id, so the settings form's
     @method('PUT') is not inherited and this can post. --}}
<form id="go-live-reset-form" method="POST" action="{{ route('admin.settings.go-live-reset') }}"
      data-confirm="This permanently deletes the records you ticked. It cannot be undone. Continue?">@csrf</form>
@section('title', 'Configurations')

@section('content')
@php
    $tabs = [
        'promotion' => ['Promotion Configuration', 'fa-sliders'],
        'company' => ['Company Information', 'fa-circle-info'],
        'contact' => ['Company Contact', 'fa-phone'],
        'system' => ['System Configuration', 'fa-gear'],
        'frontend' => ['Frontend Configuration', 'fa-display'],
        'agent' => ['Agent Payment Config', 'fa-hand-holding-dollar'],
        'bonus' => ['Deposit Bonus', 'fa-gift'],
        'security' => ['Security', 'fa-shield-halved'],
    ];
    $value = fn (string $key, mixed $default = '') => old($key, $settings[$key] ?? $default);
@endphp

<div class="admin-page-heading configuration-heading">
    <div><p>Management</p><h1>Configurations</h1><span>Manage platform information, limits, promotions, and payment defaults.</span></div>
</div>

<nav class="configuration-tabs" aria-label="Configuration sections">
    @foreach ($tabs as $key => [$label, $icon])
        <a class="{{ $activeTab === $key ? 'active' : '' }}" href="{{ route('admin.settings.index', ['tab' => $key]) }}">
            <i class="fa-solid {{ $icon }}"></i><span>{{ $label }}</span>
        </a>
    @endforeach
</nav>

@if ($activeTab === 'promotion')
    <form method="POST" action="{{ route('admin.settings.update') }}" hidden aria-hidden="true">
        @csrf @method('PUT')
        <input name="support_email" value="{{ $settings['support_email'] ?? 'support@lucyplay.local' }}">
        <input name="welcome_credits" value="{{ $settings['welcome_credits'] ?? 5 }}">
        <input name="deposit_bonus_amount" value="{{ $settings['deposit_bonus_amount'] ?? 2500 }}">
        <input name="maintenance_message" value="{{ $settings['maintenance_message'] ?? '' }}">
    </form>
    <div class="configuration-toolbar">
        <span>{{ $promotions->count() }} {{ Str::plural('promotion', $promotions->count()) }}</span>
        <button type="button" class="configuration-primary-button" data-member-modal-open="configuration-promotion-modal">
            <i class="fa-solid fa-plus"></i> Add Promotion
        </button>
    </div>

    <section class="configuration-panel configuration-promotions">
        <div class="configuration-panel-title"><h2>Promotions</h2></div>
        @forelse ($promotions as $promotion)
            <article class="configuration-promotion-row">
                <div class="configuration-promotion-image accent-{{ $promotion->accent }}">
                    @if ($promotion->image_source)
                        <img src="{{ $promotion->image_source }}" alt="{{ $promotion->title }}" loading="lazy">
                    @else
                        <i class="fa-solid fa-gift"></i>
                    @endif
                </div>
                <div class="configuration-promotion-copy">
                    <small>{{ str($promotion->promotion_type ?: 'other')->replace('_', ' ')->title() }}</small>
                    <h3>{{ $promotion->title }}</h3>
                    <p>{{ $promotion->description }}</p>
                </div>
                <span class="configuration-status {{ $promotion->active ? 'active' : '' }}">{{ $promotion->active ? 'Active' : 'Paused' }}</span>
                <div class="configuration-row-actions">
                    <form method="POST" action="{{ route('admin.promotions.toggle', $promotion) }}">@csrf @method('PATCH')
                        <button type="submit">{{ $promotion->active ? 'Pause' : 'Publish' }}</button>
                    </form>
                    <form method="POST" action="{{ route('admin.promotions.destroy', $promotion) }}" data-confirm="Delete this promotion?" >@csrf @method('DELETE')
                        <button type="submit" class="danger" aria-label="Delete {{ $promotion->title }}"><i class="fa-regular fa-trash-can"></i></button>
                    </form>
                </div>
            </article>
        @empty
            <div class="configuration-empty-state">
                <span><i class="fa-regular fa-image"></i></span>
                <h3>No Promotion found</h3>
                <p>Create the first offer shown to players.</p>
            </div>
        @endforelse
    </section>

    <dialog id="configuration-promotion-modal" class="reference-member-modal configuration-modal" data-member-modal @if(old('configuration_form')) data-modal-auto-open @endif>
        <form method="POST" action="{{ route('admin.promotions.store') }}" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="configuration_form" value="1">
            <header>
                <div><small>Promotion configuration</small><h2>Add Promotion</h2><p>Upload artwork and choose where the promotion belongs.</p></div>
                <button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
            </header>
            <div class="reference-member-modal-body configuration-modal-body">
                <label class="game-image-dropzone configuration-image-dropzone" data-image-dropzone>
                    <input type="file" name="image_upload" accept="image/png,image/jpeg,image/webp,image/gif" data-image-input required>
                    <img src="" alt="Promotion preview" data-image-preview hidden>
                    <span class="game-upload-icon"><i class="fa-solid fa-arrow-up-from-bracket"></i></span>
                    <strong data-upload-title>Drag & drop image here, or click to select</strong>
                    <small>PNG, JPG, WEBP or GIF up to 5MB</small>
                </label>
                <div class="configuration-form-grid two">
                    <label>Name<input name="title" value="{{ old('title') }}" maxlength="120" required></label>
                    <label>Promotion Type
                        <select name="promotion_type" required>
                            <option value="">Select promotion type</option>
                            @foreach (['spin_bonus' => 'Spin Bonus', 'raffle' => 'Raffle', 'loyalty_bonus' => 'Loyalty Bonus', 'tournament' => 'Tournament', 'crash_jackpot' => 'Crash Jackpot', 'other' => 'Other'] as $key => $label)
                                <option value="{{ $key }}" @selected(old('promotion_type') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
                <label>Description<textarea name="description" rows="5" maxlength="500" required>{{ old('description') }}</textarea></label>
            </div>
            <footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit"><i class="fa-solid fa-check"></i> Save</button></footer>
        </form>
    </dialog>
@else
    <section class="configuration-panel configuration-form-panel">
        @if ($activeTab === 'company')
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="company">
                <div class="configuration-panel-title"><div><h2>Company Information</h2><p>Public identity and market information.</p></div><span>ETB market</span></div>
                <div class="configuration-form-grid two">
                    <label>Name<input name="company_name" value="{{ $value('company_name', 'Vamoseone') }}" required></label>
                    <label>Domain<input name="company_domain" value="{{ $value('company_domain', config('app.url')) }}" placeholder="https://example.com"></label>
                    <label>Country<input name="company_country" value="{{ $value('company_country', 'Ethiopia') }}" required></label>
                    <label>Country Code<input name="company_country_code" value="{{ $value('company_country_code', 'ET') }}" maxlength="2" required></label>
                    <label>Language<input name="company_language" value="{{ $value('company_language', 'English') }}" required></label>
                </div>
                <div class="configuration-form-actions"><button type="submit"><i class="fa-regular fa-pen-to-square"></i> Save Information</button></div>
            </form>
        @elseif ($activeTab === 'contact')
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="contact">
                <div class="configuration-panel-title"><div><h2>Company Contact</h2><p>Support and social contact points shown across the platform.</p></div></div>
                <div class="configuration-form-grid two">
                    <label>Email<input type="email" name="support_email" value="{{ $value('support_email', 'support@lucyplay.local') }}" required></label>
                    <label>Phone Numbers<input name="phone_numbers" value="{{ $value('phone_numbers') }}" placeholder="+251 900 000 000"></label>
                    <label>Facebook<input type="url" name="facebook_url" value="{{ $value('facebook_url') }}" placeholder="https://facebook.com/..."></label>
                    <label>Twitter / X<input type="url" name="twitter_url" value="{{ $value('twitter_url') }}" placeholder="https://x.com/..."></label>
                    <label>Instagram<input type="url" name="instagram_url" value="{{ $value('instagram_url') }}" placeholder="https://instagram.com/..."></label>
                    <label>Telegram Support Username<input name="telegram_support_username" value="{{ $value('telegram_support_username', 'FaithAdam') }}" placeholder="FaithAdam or @FaithAdam"><small>Telegram handle for player chat/support (defaults to @FaithAdam)</small></label>
                    <label>Telegram Channel<input type="url" name="telegram_url" value="{{ $value('telegram_url') }}" placeholder="https://t.me/..."></label>
                </div>
                <div class="configuration-form-actions"><button type="submit"><i class="fa-regular fa-pen-to-square"></i> Save Information</button></div>
            </form>
        @elseif ($activeTab === 'system')
            {{-- Stands alone, outside the settings form on purpose. That form
                 spoofs PUT via @method, and a button inside it carries the spoof
                 whatever its formmethod says — which sent PUT to a POST-only
                 route and answered 405. A separate form has no spoof to inherit;
                 the button below reaches it by id, which HTML allows across the
                 whole document regardless of nesting. --}}
            <form id="qtech-test-form" method="POST" action="{{ route('admin.settings.qtech.test') }}">@csrf</form>
            <form id="qtech-credentials-form" method="POST" action="{{ route('admin.settings.qtech.credentials') }}">@csrf</form>
            <form id="qtech-repair-form" method="POST" action="{{ route('admin.settings.qtech.repair') }}">@csrf</form>
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="system">
                <div class="configuration-panel-title"><div><h2>System Configuration</h2><p>Global Birr transaction limits. All values are stored in ETB.</p></div><span>Currency: ETB</span></div>


                <div class="configuration-form-grid three">
                    <label>Max Payout<input type="number" step="0.01" min="0" name="max_payout" value="{{ $value('max_payout', 1000000) }}" required><small class="unit">ETB</small></label>
                    <label>Min Deposit Amount<input type="number" step="0.01" min="0" name="min_deposit_amount" value="{{ $value('min_deposit_amount', 50) }}" required><small class="unit">ETB</small></label>
                    <label>Max Deposit Amount<input type="number" step="0.01" min="0" name="max_deposit_amount" value="{{ $value('max_deposit_amount', 100000) }}" required><small class="unit">ETB</small></label>
                    <label>Min Withdraw Amount<input type="number" step="0.01" min="0" name="min_withdraw_amount" value="{{ $value('min_withdraw_amount', 30) }}" required><small class="unit">ETB</small></label>
                    <label>Max Withdraw Amount<input type="number" step="0.01" min="0" name="max_withdraw_amount" value="{{ $value('max_withdraw_amount', 30000) }}" required><small class="unit">ETB</small></label>
                    <label>Max Transfer Amount<input type="number" step="0.01" min="0" name="max_transfer_amount" value="{{ $value('max_transfer_amount', 50000) }}" required><small class="unit">ETB</small></label>
                    <label>Min Transfer Amount<input type="number" step="0.01" min="0" name="min_transfer_amount" value="{{ $value('min_transfer_amount', 10) }}" required><small class="unit">ETB</small></label>
                    <label>Daily Max Withdraw<input type="number" step="0.01" min="0" name="daily_max_withdraw" value="{{ $value('daily_max_withdraw', 100000) }}" required><small class="unit">ETB</small></label>
                    <label>Daily Max Transfer<input type="number" step="0.01" min="0" name="daily_max_transfer" value="{{ $value('daily_max_transfer', 100000) }}" required><small class="unit">ETB</small></label>
                    <label>Daily Max Deposit<input type="number" step="0.01" min="0" name="daily_max_deposit" value="{{ $value('daily_max_deposit', 250000) }}" required><small class="unit">ETB</small></label>
                </div>

                {{-- QTech, in two directions. Placed after the Birr limits
                     because the panel header describes those; these are a
                     distinct concern grouped under their own headings. --}}
                <div class="configuration-form-section divided">
                    <h3>QTech wallet pass keys</h3>
                    <p>The secret QTech present on every wallet callback. Write-only: a saved key is never shown again, and leaving a box blank keeps the one already stored.</p>
                </div>
                <div class="configuration-form-grid two">
                    <label>Production key
                        <input type="password" name="qtech_pass_key" autocomplete="new-password"
                               placeholder="{{ $qtechKeySet ? 'Saved — type a new one to replace it' : 'Not set' }}">
                        <small>
                            @if ($qtechKeySet)
                                Saved. Guards the <code>/qtech/</code> endpoints.
                            @else
                                <strong>Not set.</strong> Every QTech request is refused until this matches the key QTech hold.
                            @endif
                        </small>
                    </label>
                    <label>Staging key
                        <input type="password" name="qtech_pass_key_staging" autocomplete="new-password"
                               placeholder="{{ $qtechStagingKeySet ? 'Saved — type a new one to replace it' : 'Not set' }}">
                        <small>
                            @if ($qtechStagingKeySet)
                                Saved. Guards the <code>/qtech-staging/</code> endpoints only.
                            @else
                                <strong>Not set.</strong> Guards <code>/qtech-staging/</code>. Staging stays refused until this is set — it never falls back to the production key.
                            @endif
                        </small>
                    </label>
                </div>

                <div class="configuration-form-section divided">
                    <h3>QTech API access</h3>
                    <p>The account QTech issued for calling their platform. Needed to sync their game list and to open their games.</p>
                </div>
                <div class="configuration-form-grid three">
                    <label>API base URL
                        <input type="url" name="qtech_api_base_url" value="{{ old('qtech_api_base_url', $qtechApi['base_url']) }}" placeholder="https://api-int.qtplatform.com">
                        <small>The integration host for now. QTech issue a production host to switch to.</small>
                    </label>
                    <label>API username
                        <input name="qtech_api_username" value="{{ old('qtech_api_username', $qtechApi['username']) }}" placeholder="api_YourBrand" autocomplete="off">
                        <small>Issued by QTech alongside the back-office account.</small>
                    </label>
                    <label>API password
                        <input type="password" name="qtech_api_password" autocomplete="new-password"
                               placeholder="{{ $qtechApi['password_set'] ? 'Saved — type a new one to replace it' : 'Not set' }}">
                        <small>{{ $qtechApi['password_set'] ? 'Saved. Leave blank to keep it.' : 'Required before QTech games can sync or launch.' }}</small>
                    </label>
                </div>
                <div class="configuration-form-section">
                    <h3>QTech games — go-live</h3>
                    <p>QTech games stay hidden from players and cannot be launched by them until this is on. It has no effect while the API base URL is QTech's integration host — integration games are never shown. Turn it on only after QTech confirm their production Common Wallet test.</p>
                </div>
                <div class="configuration-form-grid two">
                    <label class="configuration-checkbox">
                        <input type="checkbox" name="qtech_live_to_players" value="1" @checked($qtechLiveToPlayers)>
                        <span>QTech games are live to players</span>
                        <small>
                            @if ($qtechIntegrationMode)
                                Currently on the integration host — games are hidden regardless of this switch.
                            @elseif ($qtechLiveToPlayers)
                                <strong>Live.</strong> Real players can see and launch QTech games.
                            @else
                                <strong>Off.</strong> Only the test player can open QTech games.
                            @endif
                        </small>
                    </label>
                </div>
                <div class="configuration-form-section divided">
                    <h3>Schema updates</h3>
                    <p>Whether a deploy applies schema changes depends on this app's build command in the hosting dashboard, and it is not the same on both brands. This shows what is waiting and applies it without one.</p>
                </div>
                @if ($migrations === null)
                    <p class="admin-empty">The migration state could not be read on this connection.</p>
                @elseif (! $migrations['supported'])
                    <p class="admin-empty">This database has no migrations table yet, so nothing has ever been applied here.</p>
                @elseif ($migrations['pending'] === [])
                    <p class="admin-empty">Up to date — all {{ $migrations['applied'] }} migration(s) applied.</p>
                @else
                    <div class="configuration-form-feedback">
                        <div class="form-errors">
                            <strong>{{ count($migrations['pending']) }} schema update(s) are waiting.</strong>
                            They will not apply on their own unless this app's build command runs
                            <code>php artisan migrate</code>. Until then the code is deployed but the database is not.
                        </div>
                    </div>
                    <ul class="configuration-code-block">
                        @foreach ($migrations['pending'] as $pending)<li><code>{{ $pending }}</code></li>@endforeach
                    </ul>
                    <div class="configuration-inline-action">
                        <button type="submit" form="run-migrations-form"><i class="fa-solid fa-database"></i> Apply schema updates</button>
                        <p>Runs the waiting migrations against this database. Nothing is seeded and no existing row is rewritten — each migration does only what it says. Safe to press twice: anything already applied is skipped.</p>
                    </div>
                @endif
                @if ($errors->has('migrations'))
                    <div class="configuration-form-feedback"><div class="form-errors">{{ $errors->first('migrations') }}</div></div>
                @endif
                <div class="configuration-form-section divided">
                    <h3>BigBang — retired</h3>
                    <p>BigBang has been retired in favour of QTech. Its games are hidden from players and cannot be launched, and its catalogue no longer synchronises. Its wallet endpoints stay reachable so that any round still in flight can settle.</p>
                </div>
                <div class="configuration-form-grid two">
                    <label class="configuration-checkbox">
                        <input type="checkbox" name="bigbang_live" value="1" @checked(! $bigbangRetired)>
                        <span>Bring BigBang back</span>
                        <small>
                            @if ($bigbangRetired)
                                <strong>Retired.</strong> Leave this alone unless BigBang is being reinstated.
                            @else
                                <strong>Live.</strong> BigBang games are visible to players again.
                            @endif
                        </small>
                    </label>
                </div>
                @if ($errors->has('qtech'))
                    <div class="configuration-form-feedback"><div class="form-errors">{{ $errors->first('qtech') }}</div></div>
                @endif
                <div class="configuration-inline-action">
                    <button type="submit" form="qtech-test-form" formnovalidate><i class="fa-solid fa-plug"></i> Test QTech Connection</button>
                    <p>Save first, then test. The check runs from this server and reports the address QTech see it from.</p>
                </div>

                {{-- Fixtures for QTech's verification program. Wallet sessions
                     normally exist only once a real player launches a game,
                     which cannot happen before QTech certify the wallet with
                     this tool — so an operator mints them here. --}}
                <div class="configuration-form-section divided">
                    <h3>QTech verification test credentials</h3>
                    <p>QTech's test program needs a dedicated test player and wallet-session tokens that exist in this wallet. Issuing creates or resets the <code>qtech_test</code> and <code>qtech_blocked</code> players with a {{ number_format(\App\Services\QtechTestCredentials::BALANCE) }} {{ \App\Support\Qtech::currency() }} synthetic balance and mints fresh tokens. Earlier tokens are retired.</p>
                </div>
                <div class="configuration-inline-action">
                    <button type="submit" form="qtech-credentials-form"><i class="fa-solid fa-key"></i> Issue Test Credentials</button>
                    <button type="submit" form="qtech-repair-form"><i class="fa-solid fa-wrench"></i> Repair Test Players</button>
                    <p>Issue rotates the tokens (resend the file to QTech). Repair only restores the two accounts' active flags and balances and keeps every token — use it if QTech report <code>ACCOUNT_BLOCKED</code> on the test player. Do not edit <code>qtech_test</code> or <code>qtech_blocked</code> in Members.</p>
                </div>
                @if ($qtechTestCredentials)
                    <div class="configuration-code-block">
                        <h4>cw_qtcw_tester.cfg — staging</h4>
                        <pre>{{ $qtechTestConfigs['staging'] }}</pre>
                        <h4>cw_qtcw_tester.cfg — production</h4>
                        <pre>{{ $qtechTestConfigs['production'] }}</pre>
                        <p>Replace the <code>passkey</code> placeholder with the matching key you saved above. Test player: <code>{{ $qtechTestCredentials['player_id'] }}</code> · blocked player: <code>{{ $qtechTestCredentials['blocked_player_id'] }}</code> · balance {{ number_format($qtechTestCredentials['balance']) }} {{ $qtechTestCredentials['currency'] }}.</p>
                        @if (! empty($qtechTestCredentials['player_password']))
                            <h4>Play-test sign-in</h4>
                            <pre>username: {{ $qtechTestCredentials['player_id'] }}
password: {{ $qtechTestCredentials['player_password'] }}</pre>
                            <p>While the API base URL is QTech's integration host, QTech games are hidden from players and only this account can open them — sign in as it on the player site to play-test. Shown once; issuing again sets a new password.</p>
                        @endif
                    </div>
                @endif
                <div class="configuration-form-actions"><button type="submit"><i class="fa-solid fa-check"></i> Save Configuration</button></div>
            </form>

            <div class="configuration-form-section divided">
                <h3>Clear test data for go-live</h3>
                <p>Everything here was built and rehearsed against this database. This empties the records of what happened during testing so the platform opens with clean books. It cannot be undone, and it never touches configuration — settings, payment gateways, the game catalogue, promotions, bonus rules, administrators and agents all stay.</p>
            </div>
            @if ($goLiveReset === null)
                <p class="admin-empty">The record counts could not be read on this connection.</p>
            @elseif (collect($goLiveReset)->sum('count') === 0)
                <p class="admin-empty">Nothing to clear — the books are already empty.</p>
            @else
                <div class="golive-reset-list">
                    @foreach ($goLiveReset as $key => $group)
                        <label class="golive-reset-row {{ $group['count'] === 0 ? 'is-empty' : '' }}">
                            <input type="checkbox" name="groups[]" value="{{ $key }}" form="go-live-reset-form" @disabled($group['count'] === 0)>
                            <span>
                                <strong>{{ $group['label'] }}</strong>
                                <small>{{ $group['note'] }}</small>
                            </span>
                            <b>{{ number_format($group['count']) }}</b>
                        </label>
                    @endforeach
                </div>
                <div class="configuration-inline-action">
                    <label>Type {{ \App\Services\GoLiveReset::CONFIRMATION }} to confirm
                        <input name="confirmation" form="go-live-reset-form" autocomplete="off" placeholder="{{ \App\Services\GoLiveReset::CONFIRMATION }}">
                        <small>Nothing is deleted unless this matches and at least one row above is ticked.</small>
                    </label>
                    <button type="submit" class="danger" form="go-live-reset-form"><i class="fa-solid fa-eraser"></i> Clear the ticked records</button>
                </div>
            @endif
        @elseif ($activeTab === 'frontend')
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="frontend">
                <div class="configuration-panel-title"><div><h2>Frontend Configuration</h2><p>Player-facing identity, starting balance, theme, and daily tournament prizes.</p></div></div>
                <div class="configuration-form-grid two">
                    <label>Site Title<input name="site_title" value="{{ $value('site_title', 'Vamoseone') }}" required></label>
                    <label>Default Theme<select name="default_theme" required><option value="light" @selected($value('default_theme', 'system') === 'light')>Light</option><option value="dark" @selected($value('default_theme', 'system') === 'dark')>Dark</option><option value="system" @selected($value('default_theme', 'system') === 'system')>System preference</option></select></label>
                    <label>Welcome Credits<input type="number" name="welcome_credits" min="0" max="1000000" value="{{ $value('welcome_credits', 5) }}" required><small class="unit">ETB</small></label>
                    <label>Deposit Bonus<input type="number" name="deposit_bonus_amount" min="0" max="1000000" value="{{ $value('deposit_bonus_amount', 2500) }}" required><small class="unit">ETB</small></label>
                    <label class="full">Frontend Tagline<input name="frontend_tagline" value="{{ $value('frontend_tagline', 'Play bright. Play local.') }}"></label>
                    <label class="full">Maintenance Message<textarea name="maintenance_message" rows="4">{{ $value('maintenance_message') }}</textarea></label>
                </div>
                <div class="configuration-form-section divided"><h3>Daily Tournament Prizes</h3><p>Prize pool paid by the Vamoseone team after the board closes. Prizes are NOT auto-credited.</p></div>
                <div class="configuration-form-grid three">
                    <label>1st Place<input type="number" step="0.01" min="0" name="tournament_prize_first" value="{{ $value('tournament_prize_first', 500) }}" required><small class="unit">ETB</small></label>
                    <label>2nd Place<input type="number" step="0.01" min="0" name="tournament_prize_second" value="{{ $value('tournament_prize_second', 250) }}" required><small class="unit">ETB</small></label>
                    <label>3rd Place<input type="number" step="0.01" min="0" name="tournament_prize_third" value="{{ $value('tournament_prize_third', 100) }}" required><small class="unit">ETB</small></label>
                    <label>Other Places<input type="number" step="0.01" min="0" name="tournament_prize_other" value="{{ $value('tournament_prize_other', 50) }}" required><small class="unit">ETB for 4–N</small></label>
                    <label>Paid Places<input type="number" min="0" max="50" name="tournament_paid_places" value="{{ $value('tournament_paid_places', 10) }}" required><small class="unit">Top N</small></label>
                </div>
                <div class="configuration-form-actions"><button type="submit"><i class="fa-solid fa-check"></i> Save Frontend</button></div>
            </form>

            {{-- Its own form, outside the PUT-spoofed one above: a file upload
                 needs multipart and a plain POST route (see CLAUDE.md). --}}
            <form method="POST" action="{{ route('admin.settings.hero') }}" enctype="multipart/form-data" class="hero-banner-form">@csrf
                <div class="configuration-form-section divided">
                    <h3>Hero banner</h3>
                    <p>The large picture at the top of the lobby for signed-out visitors. Upload a wide image for desktop and tablet, and optionally a taller one for phones. JPG, PNG or WebP up to 6MB; {{ $hero['optimised'] ? 'uploads are resized and converted to WebP automatically.' : 'this server cannot resize images, so upload them at the size you want served.' }}</p>
                </div>
                @foreach (['hero_desktop', 'hero_mobile', 'hero_image_alt'] as $heroField)
                    @if ($errors->has($heroField))
                        <div class="configuration-form-feedback"><div class="form-errors">{{ $errors->first($heroField) }}</div></div>
                    @endif
                @endforeach
                <div class="configuration-form-grid two hero-banner-grid">
                    <div class="hero-banner-slot">
                        <div class="hero-banner-preview hero-banner-preview-wide">
                            <img src="{{ $hero['desktop_url'] }}" alt="Current desktop hero image">
                            <span class="hero-banner-badge">{{ $hero['desktop_custom'] ? 'Custom' : 'Default artwork' }}</span>
                        </div>
                        @if ($hero['desktop_missing'])
                            <div class="form-errors">The saved desktop image is no longer on the server, so visitors see the default artwork. Upload it again, or tick reset to clear it.</div>
                        @endif
                        <label class="game-image-dropzone hero-banner-dropzone" data-image-dropzone>
                            <input type="file" name="hero_desktop" accept="image/png,image/jpeg,image/webp" data-image-input>
                            <img src="" alt="Desktop preview" data-image-preview hidden>
                            <span class="game-upload-icon"><i class="fa-solid fa-arrow-up-from-bracket"></i></span>
                            <strong data-upload-title>Desktop &amp; tablet image</strong>
                            <small>At least 960×360. 1600×720 or wider recommended.</small>
                        </label>
                        <label class="configuration-checkbox">
                            <input type="checkbox" name="remove_desktop" value="1" @disabled(! $hero['desktop_custom'] && ! $hero['desktop_missing'])>
                            <span>Reset desktop to the default artwork</span>
                        </label>
                    </div>
                    <div class="hero-banner-slot">
                        <div class="hero-banner-preview hero-banner-preview-tall">
                            <img src="{{ $hero['mobile_url'] }}" alt="Current mobile hero image">
                            <span class="hero-banner-badge">{{ $hero['mobile_custom'] ? 'Custom' : ($hero['desktop_custom'] ? 'Uses desktop image' : 'Default artwork') }}</span>
                        </div>
                        @if ($hero['mobile_missing'])
                            <div class="form-errors">The saved phone image is no longer on the server, so visitors see the default artwork. Upload it again, or tick reset to clear it.</div>
                        @endif
                        <label class="game-image-dropzone hero-banner-dropzone" data-image-dropzone>
                            <input type="file" name="hero_mobile" accept="image/png,image/jpeg,image/webp" data-image-input>
                            <img src="" alt="Mobile preview" data-image-preview hidden>
                            <span class="game-upload-icon"><i class="fa-solid fa-mobile-screen"></i></span>
                            <strong data-upload-title>Phone image (optional)</strong>
                            <small>Portrait or square, at least 480×480. Falls back to the desktop image.</small>
                        </label>
                        <label class="configuration-checkbox">
                            <input type="checkbox" name="remove_mobile" value="1" @disabled(! $hero['mobile_custom'] && ! $hero['mobile_missing'])>
                            <span>Reset phone image</span>
                        </label>
                    </div>
                    <label class="full">Image description
                        <input name="hero_image_alt" value="{{ old('hero_image_alt', $hero['alt']) }}" maxlength="160" placeholder="Casino chips, dice and cards on a dark table">
                        <small>Read by screen readers and shown if the image fails to load.</small>
                    </label>
                </div>
                <div class="configuration-form-actions"><button type="submit"><i class="fa-solid fa-image"></i> Save Hero Banner</button></div>
            </form>
        @elseif ($activeTab === 'agent')
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="agent">
                <div class="configuration-panel-title"><div><h2>Agent Payment Config</h2><p>The commission rule and Birr order limits for the whole agent network. Commission is a flat amount on qualifying orders — never a percentage of every transaction.</p></div><span>Currency: ETB</span></div>
                <div class="configuration-form-grid two">
                    <label>Commission Paid Per Order<input type="number" step="0.01" min="0" name="agent_commission_amount" value="{{ $value('agent_commission_amount', 200) }}" required><small>ETB, on deposits and withdrawals</small></label>
                    <label>Commission Threshold<input type="number" step="0.01" min="0" name="agent_commission_threshold" value="{{ $value('agent_commission_threshold', 10000) }}" required><small>ETB an order must reach to earn it</small></label>
                    <label>Commission Counting<select name="agent_commission_mode" required>
                        <option value="per_order" @selected($value('agent_commission_mode', 'per_order') !== 'per_block')>Once per qualifying order (25,000 pays 200)</option>
                        <option value="per_block" @selected($value('agent_commission_mode', 'per_order') === 'per_block')>Once per whole threshold (25,000 pays 400)</option>
                    </select><small>How large orders are counted</small></label>
                    <label>Settlement Period<input type="number" min="0" max="90" name="agent_settlement_days" value="{{ $value('agent_settlement_days', 7) }}" required><small class="unit">Days</small></label>
                    <label>Minimum Agent Order<input type="number" step="0.01" min="0" name="min_agent_order" value="{{ $value('min_agent_order', 100) }}" required><small class="unit">ETB</small></label>
                    <label>Maximum Agent Order<input type="number" step="0.01" min="0" name="max_agent_order" value="{{ $value('max_agent_order', 100000) }}" required><small class="unit">ETB</small></label>
                </div>
                <div class="configuration-form-actions"><button type="submit"><i class="fa-solid fa-check"></i> Save Agent Config</button></div>
            </form>
        @elseif ($activeTab === 'bonus')
            @php
                $bonusOn = (bool) (int) $value('deposit_bonus_enabled', 0);
                $bonusPercent = (int) $value('deposit_bonus_percent', 100);
                $bonusMax = (float) $value('deposit_bonus_max_amount', 5000);
                $bonusMin = (float) $value('deposit_bonus_min_deposit', 100);
                $example = 500.0;
                $exampleBonus = round($example * $bonusPercent / 100, 2);
                if ($bonusMax > 0) { $exampleBonus = min($exampleBonus, $bonusMax); }
            @endphp
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="bonus">
                <div class="configuration-panel-title"><div><h2>Deposit Bonus</h2><p>Extra play credit on approved deposits. It can be played but never withdrawn: players keep what it wins, not the bonus itself.</p></div>
                    <span>{{ $bonus['ready'] ? ($bonusOn ? 'Bonus on' : 'Bonus off') : 'Not set up' }}</span></div>

                <div class="security-stats">
                    <div><small>Unused bonus held by players</small><strong>{{ number_format($bonus['outstanding'], 2) }}</strong></div>
                    <div><small>Paid today</small><strong>{{ number_format($bonus['today'], 2) }}</strong></div>
                    <div><small>Paid this month</small><strong>{{ number_format($bonus['month'], 2) }}</strong></div>
                    <div><small>Bonuses this month</small><strong>{{ number_format($bonus['month_count']) }}</strong></div>
                </div>
                @unless ($bonus['ready'])
                    <div class="configuration-form-feedback"><div class="form-errors">The deposit bonus tables are not there yet. Apply the waiting schema update on the System tab. Until then the bonus cannot be paid, and deposits are not affected.</div></div>
                @endunless

                <div class="configuration-form-section">
                    <h3>Switch and size</h3>
                    <p>Turn the bonus on or off at any time. Bonuses already paid stay with the players who hold them.</p>
                </div>
                <div class="configuration-form-grid three">
                    <label class="configuration-checkbox">
                        <input type="checkbox" name="deposit_bonus_enabled" value="1" @checked($bonusOn)>
                        <span>Give a bonus on approved deposits</span>
                        <small>Off by default. Applies to deposits approved from now on.</small>
                    </label>
                    <label>Bonus percentage
                        <input type="number" name="deposit_bonus_percent" min="1" max="100" step="1" list="bonus-percent-presets" value="{{ $bonusPercent }}" required>
                        <small>Percent of the deposit. At {{ $bonusPercent }}%, a {{ number_format($example, 0) }} ETB deposit earns {{ number_format($exampleBonus, 2) }} ETB of play-only bonus. 100 matches the deposit, 50 pays half.</small>
                    </label>
                    <label>Largest single bonus
                        <input type="number" name="deposit_bonus_max_amount" min="0" step="0.01" value="{{ $bonusMax }}" required>
                        <small>ETB. 0 means no limit.</small>
                    </label>
                </div>
                <datalist id="bonus-percent-presets"><option value="10"><option value="25"><option value="50"><option value="75"><option value="100"></datalist>

                <div class="configuration-form-section divided">
                    <h3>Who qualifies</h3>
                    <p>Keeps the bonus for the players it is meant for and caps what it can cost.</p>
                </div>
                <div class="configuration-form-grid three">
                    <label>Which deposits
                        <select name="deposit_bonus_scope" required>
                            <option value="first" @selected($value('deposit_bonus_scope', 'first') !== 'every')>First deposit only</option>
                            <option value="every" @selected($value('deposit_bonus_scope', 'first') === 'every')>Every deposit</option>
                        </select>
                        <small>First deposit only is the safest setting.</small>
                    </label>
                    <label>Smallest deposit that earns it
                        <input type="number" name="deposit_bonus_min_deposit" min="0" step="0.01" value="{{ $bonusMin }}" required>
                        <small>ETB. Smaller deposits earn no bonus.</small>
                    </label>
                    <label>Most bonus one player can ever receive
                        <input type="number" name="deposit_bonus_lifetime_cap" min="0" step="0.01" value="{{ $value('deposit_bonus_lifetime_cap', 0) }}" required>
                        <small>ETB. 0 means no limit.</small>
                    </label>
                    <label>Total bonus paid per day
                        <input type="number" name="deposit_bonus_daily_budget" min="0" step="0.01" value="{{ $value('deposit_bonus_daily_budget', 0) }}" required>
                        <small>ETB across all players. Once reached, no more bonuses are paid until tomorrow. 0 means no limit.</small>
                    </label>
                    <label>Bonus lasts
                        <input type="number" name="deposit_bonus_valid_days" min="0" max="365" step="1" value="{{ $value('deposit_bonus_valid_days', 30) }}" required>
                        <small>Days. 0 means it never expires.</small>
                    </label>
                    <label class="configuration-checkbox">
                        <input type="checkbox" name="deposit_bonus_forfeit_on_withdrawal" value="1" @checked((bool) (int) $value('deposit_bonus_forfeit_on_withdrawal', 1))>
                        <span>A withdrawal cancels unused bonus</span>
                        <small>Stops a player taking their own deposit out and playing on the bonus alone. They are asked to confirm first.</small>
                    </label>
                </div>

                <div class="configuration-form-section divided">
                    <h3>How the bonus behaves</h3>
                    <p>The bonus is added to the player's balance but not to what they can withdraw. A bet is paid from the bonus first. If it wins, the bonus part of the bet goes back to the bonus and the winnings become withdrawable. If it loses, the bonus is gone. A player can therefore never turn the bonus itself into cash, whichever game they play.</p>
                </div>

                <div class="configuration-form-actions"><button type="submit"><i class="fa-solid fa-check"></i> Save Deposit Bonus</button></div>
            </form>

            <section class="configuration-panel">
                <div class="configuration-panel-title"><div><h2>Recent bonuses</h2><p>The latest 25, newest first.</p></div></div>
                @if ($bonus['grants']->isEmpty())
                    <p class="admin-empty">No deposit bonus has been paid yet.</p>
                @else
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Player</th><th>Deposit</th><th>Bonus</th><th>Unused</th><th>Status</th><th>Paid</th><th>Expires</th></tr></thead>
                            <tbody>
                                @foreach ($bonus['grants'] as $grant)
                                    <tr>
                                        <td><a href="{{ route('admin.members.show', $grant->user_id) }}">{{ $grant->user?->name ?: ('#'.$grant->user_id) }}</a></td>
                                        <td>{{ number_format((float) $grant->deposit_amount, 2) }}</td>
                                        <td>{{ number_format((float) $grant->amount, 2) }} <small>({{ rtrim(rtrim(number_format((float) $grant->percent, 2), '0'), '.') }}%)</small></td>
                                        <td>{{ number_format($grant->remaining(), 2) }}</td>
                                        <td><span class="status-badge {{ $grant->status === 'active' ? 'won' : '' }}">{{ ucfirst($grant->status) }}{{ $grant->forfeit_reason ? ' · '.$grant->forfeit_reason : '' }}</span></td>
                                        <td>{{ $grant->created_at->format('M j, H:i') }}</td>
                                        <td>{{ $grant->expires_at ? $grant->expires_at->format('M j, Y') : 'Never' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @elseif ($activeTab === 'security')
            {{-- Its own form, reached by id from inside the settings form, for the
                 same reason as the QTech test button: no inherited PUT spoof. --}}
            <form id="mail-test-form" method="POST" action="{{ route('admin.settings.mail.test') }}">@csrf</form>
            <form method="POST" action="{{ route('admin.settings.update') }}">@csrf @method('PUT')<input type="hidden" name="section" value="security">
                <div class="configuration-panel-title"><div><h2>Security</h2><p>Bot protection on sign-up and sign-in, email confirmation, and the mail server that sends it.</p></div>
                    <span>{{ $security['turnstile_configured'] ? 'Turnstile on' : 'Turnstile off' }}</span></div>

                <div class="security-stats">
                    <div><small>Sign-ups, last 24h</small><strong>{{ number_format($security['counts']['registered']) }}</strong></div>
                    <div class="{{ $security['counts']['blocked'] > 0 ? 'is-warn' : '' }}"><small>Sign-ups refused</small><strong>{{ number_format($security['counts']['blocked']) }}</strong></div>
                    <div><small>Failed sign-ins</small><strong>{{ number_format($security['counts']['login_failed']) }}</strong></div>
                    <div class="{{ $security['counts']['login_blocked'] > 0 ? 'is-warn' : '' }}"><small>Sign-ins rate-limited</small><strong>{{ number_format($security['counts']['login_blocked']) }}</strong></div>
                </div>
                @unless ($security['log_ready'])
                    <div class="configuration-form-feedback"><div class="form-errors">The security log table is not there yet. Apply the waiting schema update on the System tab; the protections below work without it, but nothing is recorded.</div></div>
                @endunless

                <div class="configuration-form-section divided">
                    <h3>Cloudflare Turnstile</h3>
                    <p>A free, mostly invisible "I am human" check. Create a widget at dash.cloudflare.com → Turnstile for this site's domain and paste both keys. The site key is public and goes into the form; the secret is only ever sent from this server to Cloudflare and is never shown again.</p>
                </div>
                <div class="configuration-form-grid three">
                    <label>Site key
                        <input name="turnstile_site_key" value="{{ old('turnstile_site_key', $security['turnstile_site_key']) }}" placeholder="0x4AAAAAAA..." autocomplete="off">
                        <small>Rendered into the sign-up form.</small>
                    </label>
                    <label>Secret key
                        <input type="password" name="turnstile_secret_key" autocomplete="new-password" placeholder="{{ $security['turnstile_secret_set'] ? 'Saved — type a new one to replace it' : 'Not set' }}">
                        <small>
                            @if ($security['turnstile_configured'])
                                <strong>Active.</strong> Every sign-up is checked with Cloudflare.
                            @elseif ($security['turnstile_secret_set'])
                                Secret saved; add the site key to switch it on.
                            @else
                                <strong>Off.</strong> Honeypot, timing and rate limits still apply.
                            @endif
                        </small>
                    </label>
                    <label class="configuration-checkbox">
                        <input type="checkbox" name="turnstile_protect_login" value="1" @checked(old('turnstile_protect_login', $security['turnstile_protect_login']))>
                        <span>Also check sign-ins</span>
                        <small>Adds the widget to the login form. Off by default: the per-account rate limit already stops password guessing.</small>
                    </label>
                    <label class="configuration-checkbox">
                        <input type="checkbox" name="turnstile_clear" value="1">
                        <span>Remove both Turnstile keys</span>
                        <small>Turns the check off on save.</small>
                    </label>
                </div>

                <div class="configuration-form-section divided">
                    <h3>Sign-up rules</h3>
                    <p>Server-side limits that need no third party. Every refusal is recorded below.</p>
                </div>
                <div class="configuration-form-grid three">
                    <label>Email confirmation
                        <select name="registration_email_mode">
                            <option value="off" @selected(old('registration_email_mode', $security['email_mode']) === 'off')>Off — no email field</option>
                            <option value="optional" @selected(old('registration_email_mode', $security['email_mode']) === 'optional')>Optional — confirm if given</option>
                            <option value="required" @selected(old('registration_email_mode', $security['email_mode']) === 'required')>Required — withdrawals wait for it</option>
                        </select>
                        <small>
                            @if ($security['email_mode'] === 'required' && ! $security['mail_configured'])
                                <strong>Running as "optional":</strong> no mail server is configured below, so confirmation cannot be sent and is not enforced.
                            @elseif ($security['email_mode_effective'] === 'required')
                                <strong>Enforced.</strong> Players must confirm their email before withdrawing.
                            @else
                                "Required" holds withdrawals until the address is confirmed; deposits and play are never blocked.
                            @endif
                        </small>
                    </label>
                    <label>Minimum seconds on the form
                        <input type="number" name="registration_min_seconds" min="0" max="60" value="{{ old('registration_min_seconds', $security['min_seconds']) }}" required>
                        <small class="unit">sec</small>
                    </label>
                    <label>Max accounts per network per day
                        <input type="number" name="registration_max_per_ip_daily" min="1" max="1000" value="{{ old('registration_max_per_ip_daily', $security['max_per_ip']) }}" required>
                        <small>Per IP address. Keep it loose: Ethiopian mobile networks share one address across many people.</small>
                    </label>
                </div>

                <div class="configuration-form-section divided">
                    <h3>Outgoing mail (SMTP)</h3>
                    <p>Used for confirmation links. Dashboard environment variables do not reach this app, so the account is saved here. Leave the host blank to keep mail off.</p>
                </div>
                <div class="configuration-form-grid three">
                    <label>Host<input name="mail_host" value="{{ $value('mail_host') }}" placeholder="smtp.example.com" autocomplete="off"></label>
                    <label>Port<input type="number" name="mail_port" min="1" max="65535" value="{{ $value('mail_port', 587) }}"></label>
                    <label>Encryption
                        <select name="mail_encryption">
                            <option value="tls" @selected($value('mail_encryption', 'tls') === 'tls')>STARTTLS (port 587)</option>
                            <option value="ssl" @selected($value('mail_encryption', 'tls') === 'ssl')>SSL/TLS (port 465)</option>
                            <option value="none" @selected($value('mail_encryption', 'tls') === 'none')>None</option>
                        </select>
                    </label>
                    <label>Username<input name="mail_username" value="{{ $value('mail_username') }}" autocomplete="off"></label>
                    <label>Password
                        <input type="password" name="mail_password" autocomplete="new-password" placeholder="{{ $security['mail_password_set'] ? 'Saved — type a new one to replace it' : 'Not set' }}">
                        <small>Leave blank to keep the saved password.</small>
                    </label>
                    <label>From address<input type="email" name="mail_from_address" value="{{ $value('mail_from_address') }}" placeholder="no-reply@yourdomain.com"></label>
                    <label>From name<input name="mail_from_name" value="{{ $value('mail_from_name') }}" placeholder="{{ $value('site_title', 'Vamoseone') }}"></label>
                </div>
                @if ($errors->has('mail'))
                    <div class="configuration-form-feedback"><div class="form-errors">{{ $errors->first('mail') }}</div></div>
                @endif
                <div class="configuration-inline-action">
                    <button type="submit" form="mail-test-form" formnovalidate><i class="fa-solid fa-paper-plane"></i> Send a test email</button>
                    <p>Save first, then test. Sends to your own administrator address ({{ auth()->user()->email ?: 'none on file' }}).</p>
                </div>

                <div class="configuration-form-section divided">
                    <h3>Recent refusals</h3>
                    <p>The last 40 sign-ups and sign-ins that were turned away, newest first. A burst from one address with the same reason is a script; scattered single entries are people mistyping.</p>
                </div>
                @if ($security['events']->isEmpty())
                    <p class="admin-empty">Nothing refused yet.</p>
                @else
                    <div class="security-events-wrap">
                        <table class="security-events">
                            <thead><tr><th>When</th><th>What</th><th>Reason</th><th>Identifier</th><th>Address</th><th>Browser</th></tr></thead>
                            <tbody>
                                @foreach ($security['events'] as $event)
                                    <tr>
                                        <td title="{{ $event->created_at }}">{{ $event->created_at?->diffForHumans() }}</td>
                                        <td><span class="security-pill security-pill-{{ $event->outcome }}">{{ $event->type }} · {{ $event->outcome }}</span></td>
                                        <td><code>{{ $event->reason ?: '—' }}</code></td>
                                        <td>{{ $event->identifier ?: '—' }}</td>
                                        <td><code>{{ $event->ip ?: '—' }}</code></td>
                                        <td class="security-ua" title="{{ $event->user_agent }}">{{ \Illuminate\Support\Str::limit((string) $event->user_agent, 48) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="configuration-form-actions"><button type="submit"><i class="fa-solid fa-check"></i> Save Security</button></div>
            </form>
        @endif
    </section>
@endif
@endsection
