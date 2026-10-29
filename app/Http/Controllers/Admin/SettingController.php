<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlatformSetting;
use App\Models\Promotion;
use App\Support\CatalogCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingController
{
    private const TABS = ['promotion', 'company', 'contact', 'system', 'frontend', 'agent', 'bonus', 'security'];

    /** Write-only fields: a blank box means "keep what is stored", never "erase". */
    public const SECRETS = ['qtech_pass_key', 'qtech_pass_key_staging', 'qtech_api_password', 'turnstile_secret_key', 'mail_password', 'winhouse_api_key', 'winhouse_callback_token', 'bigbang_api_key', 'bigbang_callback_token', 'bigbang_sync_token'];

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true)
            ? $request->query('tab')
            : 'promotion';

        return view('admin.settings.index', [
            'settings' => PlatformSetting::query()->pluck('value', 'key'),
            'promotions' => Promotion::query()->latest()->get(),
            'activeTab' => $tab,
            // Whether a key exists, never the key itself.
            'qtechKeySet' => \App\Support\Qtech::configured(),
            'qtechStagingKeySet' => \App\Support\Qtech::configured(\App\Support\Qtech::STAGING),
            'qtechLiveToPlayers' => \App\Support\Qtech::liveToPlayers(),
            'bigbangRetired' => \App\Support\BigBang::retired(),
            'migrations' => $this->migrationStatus(),
            // Counted only for the tab that shows it: one COUNT per table.
            'goLiveReset' => $tab === 'system' ? $this->goLiveResetPreview() : null,
            'qtechIntegrationMode' => \App\Support\Qtech::integrationMode(),
            'winhouse' => $tab === 'system' ? $this->winhouseState($request) : null,
            'tick' => $tab === 'system' ? [
                'heartbeat' => \Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\SystemTickController::HEARTBEAT_KEY),
                'last' => \Illuminate\Support\Facades\Cache::get(\App\Http\Controllers\SystemTickController::LAST_TICK_KEY),
                'url' => $request->getSchemeAndHttpHost().'/api/system/tick',
                'token_set' => \App\Support\Qtech::syncToken() !== '',
            ] : null,
            'bigbang' => $tab === 'system' ? [
                'api_key_set' => \App\Support\BigBang::apiKey() !== '',
                'callback_set' => \App\Support\BigBang::callbackToken() !== '',
                'sync_set' => \App\Support\BigBang::syncToken() !== '',
            ] : null,
            'hero' => $tab === 'frontend' ? $this->heroState() : null,
            'security' => $tab === 'security' ? $this->securityState() : null,
            'bonus' => $tab === 'bonus' ? $this->bonusState() : null,
            'qtechApi' => [
                'base_url' => \App\Support\Qtech::apiBaseUrl(),
                'username' => \App\Support\Qtech::apiUsername(),
                'password_set' => \App\Support\Qtech::apiPassword() !== '',
            ],
            // Shown once, straight after issuing: the flash survives one redirect.
            'qtechTestCredentials' => $issued = $request->session()->get('qtech_test_credentials'),
            'qtechTestConfigs' => $issued ? [
                'staging' => app(\App\Services\QtechTestCredentials::class)->configFile($issued, $request->getSchemeAndHttpHost(), \App\Support\Qtech::STAGING),
                'production' => app(\App\Services\QtechTestCredentials::class)->configFile($issued, $request->getSchemeAndHttpHost(), \App\Support\Qtech::PRODUCTION),
            ] : null,
        ]);
    }

    /**
     * Apply whatever schema changes are waiting.
     *
     * See App\Services\PendingMigrations: whether a deploy runs migrations at
     * all depends on a string in the hosting dashboard, and on this brand it
     * does not. Without this there is no way to apply one — the change ships,
     * the build goes green, and nothing happens.
     */
    public function runMigrations(\App\Services\PendingMigrations $migrations): RedirectResponse
    {
        try {
            $result = $migrations->apply();
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['migrations' => 'The migration could not be completed: '.$exception->getMessage()]);
        }

        return back()->with('admin_status', $result['ran'] === []
            ? 'Nothing was waiting — the schema is already up to date.'
            : sprintf('Applied %d migration(s): %s', count($result['ran']), implode(', ', $result['ran'])));
    }

    /**
     * Clear the records left over from testing.
     *
     * Irreversible, so it is gated three ways: the operator ticks each group,
     * types the confirmation word, and the service refuses anything that is not
     * a record of something that happened. Configuration is out of its reach
     * entirely — see App\Services\GoLiveReset.
     */
    public function goLiveReset(Request $request, \App\Services\GoLiveReset $reset): RedirectResponse
    {
        $validated = $request->validate([
            'groups' => ['required', 'array', 'min:1'],
            'groups.*' => ['required', 'string', 'max:40'],
            'confirmation' => ['required', 'string', 'max:40'],
        ]);

        try {
            $removed = $reset->apply($validated['groups'], $validated['confirmation']);
        } catch (\Throwable $exception) {
            return back()->withErrors(['go_live_reset' => $exception->getMessage()]);
        }

        $total = array_sum($removed);

        return back()->with('admin_status', $total === 0
            ? 'Nothing was left to clear in what you ticked.'
            : sprintf('Cleared %s record(s): %s.', number_format($total), implode(', ', array_map(
                fn ($key, $count) => $key.' '.number_format($count),
                array_keys($removed),
                $removed
            ))));
    }

    /**
     * Replace, keep or remove the lobby hero artwork.
     *
     * Each slot is independent: an upload replaces that slot, a ticked
     * "remove" box returns it to the bundled artwork, and leaving both alone
     * keeps whatever is stored. The alt text is saved every time. The old file
     * is deleted only after the new one is stored and the setting written, so
     * a failed upload never leaves the lobby pointing at a missing image.
     */
    public function updateHero(Request $request, \App\Services\HeroImageProcessor $images): RedirectResponse
    {
        foreach (['hero_desktop', 'hero_mobile', 'hero_sports'] as $field) {
            $this->ensureValidUpload($request, $field, 6);
        }

        $validated = $request->validate([
            // Raster only. SVG is deliberately refused: it can carry scripts,
            // and the hero is served to every anonymous visitor.
            'hero_desktop' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=960,min_height=360'],
            'hero_mobile' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=480,min_height=480'],
            // The sports band is wide and short: 1600×260 is the drawn default's shape.
            'hero_sports' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144', 'dimensions:min_width=960,min_height=150'],
            'hero_image_alt' => ['nullable', 'string', 'max:160'],
            'remove_desktop' => ['nullable', 'boolean'],
            'remove_mobile' => ['nullable', 'boolean'],
            'remove_sports' => ['nullable', 'boolean'],
        ]);

        $values = [\App\Support\HeroBanner::SETTING_ALT => trim((string) ($validated['hero_image_alt'] ?? ''))];
        $clear = [];
        $changed = [];

        try {
            foreach (['desktop' => 'hero_desktop', 'mobile' => 'hero_mobile', 'sports' => 'hero_sports'] as $slot => $field) {
                $settingKey = \App\Support\HeroBanner::settingKey($slot);
                $sizeKey = \App\Support\HeroBanner::sizeKey($slot);
                // The reference as saved, even when its image is gone, so a broken
                // one can still be reset.
                $current = \App\Support\HeroBanner::storedReference($slot);

                if ($request->hasFile($field)) {
                    $values[$settingKey] = $images->store($request->file($field), $slot);
                    // The picture's own proportions, so the lobby can give the hero its height.
                    $dims = @getimagesize($request->file($field)->getRealPath());
                    $values[$sizeKey] = $dims ? $dims[0].'x'.$dims[1] : '';
                    // The new image replaced the slot's row in place; only a legacy
                    // disk file is left over.
                    $images->deleteLegacyFile($current);
                    $changed[] = $slot;
                } elseif ($request->boolean('remove_'.$slot) && $current !== null) {
                    $values[$settingKey] = '';
                    $values[$sizeKey] = '';
                    $clear[$slot] = $current;
                    $changed[] = $slot.' (reset)';
                }
            }
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['hero_desktop' => 'The image could not be saved: '.$exception->getMessage()]);
        }

        $this->persist($values);

        foreach ($clear as $slot => $reference) {
            $images->clear($slot, $reference);
        }
        return redirect()
            ->route('admin.settings.index', ['tab' => 'frontend'])
            ->with('admin_status', $changed === []
                ? 'Hero banner text saved.'
                : 'Hero banner updated: '.implode(', ', $changed).'.');
    }

    /**
     * What the Security tab shows: whether each protection is live, the last
     * day's counts, and the most recent refusals. Every query is wrapped — a
     * brand whose deploy has not run the migration yet still gets the form.
     */
    private function securityState(): array
    {
        $since = now()->subDay();
        $counts = ['registered' => 0, 'blocked' => 0, 'login_failed' => 0, 'login_blocked' => 0];
        $events = collect();

        try {
            $counts['registered'] = \App\Models\SecurityEvent::query()->where('type', \App\Models\SecurityEvent::TYPE_REGISTRATION)->where('outcome', \App\Models\SecurityEvent::OUTCOME_ALLOWED)->since($since)->count();
            $counts['blocked'] = \App\Models\SecurityEvent::query()->where('type', \App\Models\SecurityEvent::TYPE_REGISTRATION)->where('outcome', \App\Models\SecurityEvent::OUTCOME_BLOCKED)->since($since)->count();
            $counts['login_failed'] = \App\Models\SecurityEvent::query()->where('type', \App\Models\SecurityEvent::TYPE_LOGIN)->where('outcome', \App\Models\SecurityEvent::OUTCOME_FAILED)->since($since)->count();
            $counts['login_blocked'] = \App\Models\SecurityEvent::query()->where('type', \App\Models\SecurityEvent::TYPE_LOGIN)->where('outcome', \App\Models\SecurityEvent::OUTCOME_BLOCKED)->since($since)->count();
            $events = \App\Models\SecurityEvent::query()->suspicious()->latest('id')->limit(40)->get();
            $logReady = true;
        } catch (\Throwable) {
            $logReady = false;
        }

        return [
            'turnstile_site_key' => \App\Support\Turnstile::siteKey(),
            'turnstile_secret_set' => \App\Support\Turnstile::secret() !== '',
            'turnstile_configured' => \App\Support\Turnstile::configured(),
            'turnstile_protect_login' => (bool) PlatformSetting::valueFor(\App\Support\Turnstile::SETTING_PROTECT_LOGIN, false),
            'email_mode' => \App\Services\RegistrationGuard::configuredEmailMode(),
            'email_mode_effective' => \App\Services\RegistrationGuard::emailMode(),
            'mail_configured' => \App\Support\Mailer::configured(),
            'mail_password_set' => \App\Support\RuntimeEnv::get(null, 'mail_password', 'MAIL_PASSWORD') !== '',
            'min_seconds' => \App\Services\RegistrationGuard::minSeconds(),
            'max_per_ip' => \App\Services\RegistrationGuard::maxPerIpDaily(),
            'counts' => $counts,
            'events' => $events,
            'log_ready' => $logReady,
        ];
    }

    /**
     * What the System tab shows for the WinHouse sportsbook: whether each key
     * resolves and from where, never a key itself, plus the wallet address to
     * hand to WinHouse for this host.
     */
    private function winhouseState(Request $request): array
    {
        $sources = [
            'iframe_key' => \App\Support\Winhouse::source('iframe_key'),
            'api_key' => \App\Support\Winhouse::source('api_key'),
            'callback_token' => \App\Support\Winhouse::source('callback_token'),
        ];
        $labels = ['iframe_key' => 'iframe key', 'api_key' => 'wallet API key', 'callback_token' => 'callback token'];

        return [
            'iframe_key' => \App\Support\Winhouse::iframeKey(),
            'api_key_set' => \App\Support\Winhouse::apiKey() !== '',
            'callback_set' => \App\Support\Winhouse::callbackToken() !== '',
            'mode' => \App\Support\Winhouse::mode(),
            'currency' => \App\Support\Winhouse::currency(),
            'sources' => $sources,
            'missing' => array_values(array_map(fn ($k) => $labels[$k], array_keys(array_filter($sources, fn ($s) => $s === '')))),
            'configured' => \App\Support\Winhouse::configured(),
            'host' => $request->getHost(),
            'wallet_base_url' => $request->getSchemeAndHttpHost().'/api/integrations/winhouse',
        ];
    }

    /**
     * What the Deposit Bonus tab shows: whether the tables exist, what is owed to
     * players as unused bonus, what was paid today and this month, and the latest
     * grants. Wrapped, like the other panels, so a brand that has not applied the
     * migration still gets the form.
     */
    private function bonusState(): array
    {
        $ready = \App\Services\DepositBonusService::tablesReady();
        $state = ['ready' => $ready, 'outstanding' => 0.0, 'today' => 0.0, 'month' => 0.0, 'month_count' => 0, 'grants' => collect()];
        if (! $ready) {
            return $state;
        }

        try {
            $grants = \App\Models\DepositBonusGrant::query();
            $state['outstanding'] = (float) (clone $grants)->active()->selectRaw('COALESCE(SUM(amount - forfeited), 0) AS total')->value('total');
            $state['today'] = (float) (clone $grants)->where('created_at', '>=', now()->startOfDay())->sum('amount');
            $state['month'] = (float) (clone $grants)->where('created_at', '>=', now()->startOfMonth())->sum('amount');
            $state['month_count'] = (clone $grants)->where('created_at', '>=', now()->startOfMonth())->count();
            $state['grants'] = \App\Models\DepositBonusGrant::query()->with('user:id,name,username,phone')->latest('id')->limit(25)->get();
        } catch (\Throwable) {
            $state['ready'] = false;
        }

        return $state;
    }

    /** Send a message to the signed-in administrator with the SMTP account from the Security tab. */
    public function testMail(Request $request): RedirectResponse
    {
        if (! \App\Support\Mailer::configured()) {
            return back()->withErrors(['mail' => 'Save a mail host and a from address first.']);
        }

        $to = (string) $request->user()->email;

        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['mail' => 'Your administrator account has no email address to send the test to.']);
        }

        try {
            \App\Support\Mailer::prepare();
            \Illuminate\Support\Facades\Mail::raw(
                'This is a test message from '.(string) PlatformSetting::valueFor('site_title', config('app.name')).'. The SMTP account on the Security tab works.',
                fn ($message) => $message->to($to)->subject('Mail test — '.(string) PlatformSetting::valueFor('site_title', config('app.name')))
            );
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['mail' => 'The mail server refused the message: '.$exception->getMessage()]);
        }

        return redirect()->route('admin.settings.index', ['tab' => 'security'])
            ->with('admin_status', 'Test message sent to '.$to.'. Check that inbox (and its spam folder).');
    }

    /** What the Frontend tab shows about the hero: URLs for previews, and whether each slot is custom. */
    private function heroState(): array
    {
        return [
            'desktop_url' => \App\Support\HeroBanner::desktopUrl(),
            'mobile_url' => \App\Support\HeroBanner::mobileUrl(),
            'desktop_custom' => \App\Support\HeroBanner::isCustom('desktop'),
            'mobile_custom' => \App\Support\HeroBanner::isCustom('mobile'),
            'desktop_missing' => \App\Support\HeroBanner::isMissing('desktop'),
            'mobile_missing' => \App\Support\HeroBanner::isMissing('mobile'),
            'sports_url' => \App\Support\HeroBanner::sportsUrl(),
            'sports_custom' => \App\Support\HeroBanner::isCustom('sports'),
            'sports_missing' => \App\Support\HeroBanner::isMissing('sports'),
            'alt' => (string) PlatformSetting::valueFor(\App\Support\HeroBanner::SETTING_ALT, ''),
            'optimised' => extension_loaded('gd'),
        ];
    }

    /** PHP drops an over-limit upload before validation sees it; name that instead of "the field is required". */
    private function ensureValidUpload(Request $request, string $field, int $maxMb): void
    {
        if (! $request->hasFile($field)) {
            return;
        }

        $file = $request->file($field);

        if ($file->isValid()) {
            return;
        }

        $message = match ($file->getError()) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The uploaded image exceeds the maximum allowed file size ({$maxMb}MB).",
            UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
            default => 'The image failed to upload. Please select a JPG, PNG or WebP under '.$maxMb.'MB.',
        };

        throw \Illuminate\Validation\ValidationException::withMessages([$field => $message]);
    }

    /** Wrapped for the same reason as migrationStatus(): a panel must not 500 the page. */
    private function goLiveResetPreview(): ?array
    {
        try {
            return app(\App\Services\GoLiveReset::class)->preview();
        } catch (\Throwable) {
            return null;
        }
    }

    /** Wrapped: a diagnostic must never be the reason the settings page 500s. */
    private function migrationStatus(): ?array
    {
        try {
            return app(\App\Services\PendingMigrations::class)->status();
        } catch (\Throwable) {
            return null;
        }
    }

    public function update(Request $request): RedirectResponse
    {
        if (! $request->filled('section')) {
            $legacy = $request->validate([
                'support_email' => ['required', 'email', 'max:160'],
                'welcome_credits' => ['required', 'integer', 'min:0', 'max:1000000'],
                'deposit_bonus_amount' => ['required', 'integer', 'min:0', 'max:1000000'],
                'maintenance_message' => ['nullable', 'string', 'max:300'],
            ]);
            $this->persist($legacy);

            return back()->with('admin_status', 'Platform settings saved.');
        }

        $section = $request->validate([
            'section' => ['required', Rule::in(array_diff(self::TABS, ['promotion']))],
        ])['section'];

        $validated = $request->validate($this->rulesFor($section));

        // An unticked checkbox is absent from the request; it must still be
        // saved as off, or the switch could never be turned back off.
        if ($section === 'system') {
            $validated['qtech_live_to_players'] = $request->boolean('qtech_live_to_players');
            // Retired unless the box is ticked, which is the opposite default
            // from the switch above — an absent key must not resurrect BigBang.
            $validated['bigbang_retired'] = ! $request->boolean('bigbang_live');
        }

        if ($section === 'bonus') {
            // Unticked boxes are absent from the request and must be saved as off.
            $validated['deposit_bonus_enabled'] = $request->boolean('deposit_bonus_enabled');
            $validated['deposit_bonus_forfeit_on_withdrawal'] = $request->boolean('deposit_bonus_forfeit_on_withdrawal');
        }

        if ($section === 'security') {
            $validated['turnstile_protect_login'] = $request->boolean('turnstile_protect_login');

            // The one way to turn Turnstile off: both keys go, together.
            if ($request->boolean('turnstile_clear')) {
                $validated['turnstile_site_key'] = '';
                $validated['turnstile_secret_key'] = '';
            }
            unset($validated['turnstile_clear']);
        }

        // A blank secret box means "unchanged", never "erase". The field is
        // rendered empty every time (the value is never echoed back to the
        // browser), so persisting '' would silently revoke the integration the
        // next time anyone saved an unrelated setting on the same tab.
        foreach (self::SECRETS as $secret) {
            if (array_key_exists($secret, $validated) && trim((string) $validated[$secret]) === ''
                && ! ($section === 'security' && $request->boolean('turnstile_clear') && $secret === 'turnstile_secret_key')) {
                unset($validated[$secret]);
            }
        }

        $this->persist($validated);

        // A token minted against the old credentials or host would be presented
        // to the new one and refused; drop it so the next call re-authenticates.
        if (array_intersect_key($validated, array_flip(['qtech_api_base_url', 'qtech_api_username', 'qtech_api_password'])) !== []) {
            app(\App\Services\QtechClient::class)->forgetToken();
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => $section])
            ->with('admin_status', ucfirst($section).' configuration saved.');
    }

    private function persist(array $values): void
    {
        $rows = collect($values)
            ->map(fn ($value, $key) => [
                'key' => $key,
                'value' => is_bool($value) ? ($value ? '1' : '0') : (string) ($value ?? ''),
            ])
            ->push(['key' => 'currency_code', 'value' => 'ETB'])
            ->all();

        DB::table('platform_settings')->upsert($rows, ['key'], ['value']);
        PlatformSetting::flushValueCache();

        // A settings save is not a model event, so none of the catalog observers
        // fire — yet one of these keys, qtech_live_to_players, decides what
        // Game::scopeAvailable() returns and is meant to work as a kill switch.
        // Without this the lobby keeps serving the pre-save snapshot for its whole
        // TTL. Saves are rare, so bumping on every section costs one rebuild.
        CatalogCache::bump();
    }

    private function rulesFor(string $section): array
    {
        return match ($section) {
            'company' => [
                'company_name' => ['required', 'string', 'max:120'],
                'company_domain' => ['nullable', 'string', 'max:190'],
                'company_country' => ['required', 'string', 'max:80'],
                'company_country_code' => ['required', 'string', 'size:2'],
                'company_language' => ['required', 'string', 'max:40'],
            ],
            'contact' => [
                'support_email' => ['required', 'email', 'max:160'],
                'telegram_support_username' => ['nullable', 'string', 'max:120'],
                'facebook_url' => ['nullable', 'url', 'max:255'],
                'twitter_url' => ['nullable', 'url', 'max:255'],
                'instagram_url' => ['nullable', 'url', 'max:255'],
                'tiktok_url' => ['nullable', 'url', 'max:255'],
                'telegram_url' => ['nullable', 'url', 'max:255'],
                'phone_numbers' => ['nullable', 'string', 'max:255'],
            ],
            'system' => [
                // Optional on purpose: an empty box means "leave the stored key
                // alone", so saving any other System setting cannot wipe it.
                'qtech_pass_key' => ['nullable', 'string', 'max:190'],
                'qtech_pass_key_staging' => ['nullable', 'string', 'max:190'],
                // Our credentials for calling QTech. Password is write-only
                // like the pass keys; the other two are plain settings.
                'qtech_api_base_url' => ['nullable', 'url', 'max:190'],
                'qtech_api_username' => ['nullable', 'string', 'max:120'],
                'qtech_api_password' => ['nullable', 'string', 'max:190'],
                'qtech_live_to_players' => ['nullable', 'boolean'],
                // WinHouse sportsbook (App\Support\Winhouse). The two secrets are
                // write-only like the QTech ones; a blank box keeps the stored value.
                'winhouse_iframe_key' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'winhouse_api_key' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'winhouse_callback_token' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'winhouse_mode' => ['nullable', Rule::in(['sandbox', 'live'])],
                // BigBang (App\Support\BigBang). Write-only like the others.
                'bigbang_api_key' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'bigbang_callback_token' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'bigbang_sync_token' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'bigbang_live' => ['nullable', 'boolean'],
                'max_payout' => ['required', 'numeric', 'min:0'],
                'min_deposit_amount' => ['required', 'numeric', 'min:0'],
                'max_deposit_amount' => ['required', 'numeric', 'gte:min_deposit_amount'],
                'min_withdraw_amount' => ['required', 'numeric', 'min:0'],
                'max_withdraw_amount' => ['required', 'numeric', 'gte:min_withdraw_amount'],
                'max_transfer_amount' => ['required', 'numeric', 'min:0'],
                'min_transfer_amount' => ['required', 'numeric', 'min:0', 'lte:max_transfer_amount'],
                'daily_max_withdraw' => ['required', 'numeric', 'min:0'],
                'daily_max_transfer' => ['required', 'numeric', 'min:0'],
                'daily_max_deposit' => ['required', 'numeric', 'min:0'],
                'public_site_url' => ['nullable', 'string', 'max:190', 'regex:#^https?://[A-Za-z0-9.-]+(:\d{2,5})?/?$#'],
                'deposit_review_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
                'withdrawal_review_hours' => ['required', 'integer', 'min:1', 'max:168'],
            ],
            'frontend' => [
                'site_title' => ['required', 'string', 'max:80'],
                'frontend_tagline' => ['nullable', 'string', 'max:180'],
                'welcome_credits' => ['required', 'integer', 'min:0', 'max:1000000'],
                'deposit_bonus_amount' => ['required', 'integer', 'min:0', 'max:1000000'],
                'maintenance_message' => ['nullable', 'string', 'max:300'],
                'default_theme' => ['required', Rule::in(['light', 'dark', 'system'])],
                'tournament_prize_first' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'tournament_prize_second' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'tournament_prize_third' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'tournament_prize_other' => ['required', 'numeric', 'min:0', 'max:1000000'],
                'tournament_paid_places' => ['required', 'integer', 'min:0', 'max:50'],
            ],
            'security' => [
                'turnstile_site_key' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'turnstile_secret_key' => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9_\-]*$/'],
                'turnstile_protect_login' => ['nullable', 'boolean'],
                'turnstile_clear' => ['nullable', 'boolean'],
                'registration_email_mode' => ['required', Rule::in([
                    \App\Services\RegistrationGuard::EMAIL_OFF,
                    \App\Services\RegistrationGuard::EMAIL_OPTIONAL,
                    \App\Services\RegistrationGuard::EMAIL_REQUIRED,
                ])],
                'registration_min_seconds' => ['required', 'integer', 'min:0', 'max:60'],
                'registration_max_per_ip_daily' => ['required', 'integer', 'min:1', 'max:1000'],
                'mail_host' => ['nullable', 'string', 'max:190'],
                'mail_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
                'mail_username' => ['nullable', 'string', 'max:190'],
                'mail_password' => ['nullable', 'string', 'max:190'],
                'mail_encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
                'mail_from_address' => ['nullable', 'email', 'max:160'],
                'mail_from_name' => ['nullable', 'string', 'max:120'],
            ],
            'bonus' => [
                'deposit_bonus_enabled' => ['nullable', 'boolean'],
                // 100 matches the deposit, 50 pays half of it. Never above 100: a bonus
                // larger than the deposit is a gift, not a match.
                'deposit_bonus_percent' => ['required', 'integer', 'min:1', 'max:100'],
                'deposit_bonus_max_amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
                'deposit_bonus_min_deposit' => ['required', 'numeric', 'min:0', 'max:10000000'],
                'deposit_bonus_scope' => ['required', Rule::in(['first', 'every'])],
                'deposit_bonus_lifetime_cap' => ['required', 'numeric', 'min:0', 'max:100000000'],
                'deposit_bonus_valid_days' => ['required', 'integer', 'min:0', 'max:365'],
                'deposit_bonus_daily_budget' => ['required', 'numeric', 'min:0', 'max:1000000000'],
                'deposit_bonus_forfeit_on_withdrawal' => ['nullable', 'boolean'],
            ],
            'agent' => [
                'agent_commission_amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'agent_commission_threshold' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'agent_commission_mode' => ['required', Rule::in(['per_order', 'per_block', 'percent'])],
            'agent_commission_deposit_percent' => ['nullable', 'required_if:agent_commission_mode,percent', 'numeric', 'min:0', 'max:100'],
            'agent_commission_withdrawal_percent' => ['nullable', 'required_if:agent_commission_mode,percent', 'numeric', 'min:0', 'max:100'],
                'min_agent_order' => ['required', 'numeric', 'min:0'],
                'max_agent_order' => ['required', 'numeric', 'gte:min_agent_order'],
                'agent_settlement_days' => ['required', 'integer', 'min:0', 'max:90'],
                'agent_min_float' => ['required', 'numeric', 'min:0', 'max:100000000'],
                'agent_low_float_warning' => ['required', 'numeric', 'min:0', 'max:100000000'],
            ],
        };
    }
    /**
     * Prove the QTech credentials from this server — the only vantage point
     * that can: QTech whitelist the caller's address, so a developer's machine
     * is refused whatever the password. Also reports the address this server
     * is seen from, which is what their handover form asked for.
     */
    /**
     * Mint the test player and wallet-session tokens QTech's verifier needs.
     * Safe to press repeatedly: the balance resets and earlier tokens retire.
     */
    public function issueQtechTestCredentials(\App\Services\QtechTestCredentials $fixtures): RedirectResponse
    {
        try {
            $issued = $fixtures->issue();
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['qtech' => 'Could not issue test credentials: '.$exception->getMessage()]);
        }

        return redirect()
            ->route('admin.settings.index', ['tab' => 'system'])
            ->with('qtech_test_credentials', $issued)
            ->with('admin_status', 'QTech test credentials issued. The verifier configuration below is shown once — copy it now.');
    }

    /** Restore the fixture players' flags and balances; tokens are untouched. */
    public function repairQtechTestPlayers(\App\Services\QtechTestCredentials $fixtures): RedirectResponse
    {
        try {
            $r = $fixtures->repair();
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['qtech' => 'Could not repair the test players: '.$exception->getMessage()]);
        }

        return redirect()->route('admin.settings.index', ['tab' => 'system'])->with('admin_status', sprintf(
            'Test players repaired: %s active with %s %s, %s blocked, %d session token(s) left exactly as issued — QTech can re-run the same file.',
            $r['player_id'], number_format($r['balance']), \App\Support\Qtech::currency(), $r['blocked_player_id'], $r['live_sessions']
        ));
    }

    public function testQtech(\App\Services\QtechClient $client): RedirectResponse
    {
        if (! $client->configured()) {
            return back()->withErrors(['qtech' => 'Save the QTech API username and password first.']);
        }

        $result = $client->probe();
        $seen = $result['egress_ip'] ? ' This server calls out from '.$result['egress_ip'].'.' : '';

        return $result['ok']
            ? back()->with('admin_status', 'QTech connection OK — '.$result['message'].$seen)
            : back()->withErrors(['qtech' => 'QTech refused the connection: '.$result['message'].$seen]);
    }

    /** Can this server reach BigBang, and from which address? See BigBangCasinoClient::probe(). */
    public function testBigBang(\App\Services\BigBangCasinoClient $client): RedirectResponse
    {
        $result = $client->probe();
        $seen = $result['egress_ip'] ? ' This server calls out from '.$result['egress_ip'].'.' : ' The outgoing address could not be read.';

        return $result['ok']
            ? back()->with('admin_status', 'BigBang connection OK — '.$result['message'].$seen)
            : back()->withErrors(['bigbang' => $result['message'].$seen]);
    }

    /**
     * Prove the saved WinHouse keys work by reading one ticket through their
     * tickets API: it is the one WinHouse call this site makes with the same
     * iframe key and API key the book itself uses, and it moves no money.
     */
    public function testWinhouse(\App\Services\WinhouseTicketsClient $client): RedirectResponse
    {
        if (! \App\Support\Winhouse::configured()) {
            return back()->withErrors(['winhouse' => 'Save the WinHouse iframe key, wallet API key and callback token first.']);
        }

        $result = $client->tickets(['limit' => 1]);
        if (! $result['ok']) {
            return back()->withErrors(['winhouse' => $result['error']]);
        }

        $total = (int) ($result['meta']['total'] ?? count($result['tickets']));
        $latest = $result['tickets'][0]['placed_at'] ?? null;

        return back()->with('admin_status', 'WinHouse connection OK — the keys are accepted; '.number_format($total).' ticket(s) on the account'
            .($latest ? ', the latest placed '.$latest->diffForHumans() : '').'. Mode: '.\App\Support\Winhouse::mode().'.');
    }

}
