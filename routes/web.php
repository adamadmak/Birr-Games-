<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\MemberController as AdminMemberController;
use App\Http\Controllers\Admin\PromotionController as AdminPromotionController;
use App\Http\Controllers\Admin\RoundController as AdminRoundController;
use App\Http\Controllers\Admin\SettingController as AdminSettingController;
use App\Http\Controllers\Admin\GameEngineController as AdminGameEngineController;
use App\Http\Controllers\Admin\ShamoController as AdminShamoController;
use App\Http\Controllers\Admin\AdministratorController as AdminAdministratorController;
use App\Http\Controllers\Admin\PaymentGatewayController as AdminPaymentGatewayController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Admin\CasinoReportController as AdminCasinoReportController;
use App\Http\Controllers\Admin\CasinoTransactionController as AdminCasinoTransactionController;
use App\Http\Controllers\Admin\SportsTicketController as AdminSportsTicketController;
use App\Http\Controllers\Admin\AgentController as AdminAgentController;
use App\Http\Controllers\Admin\TelegramBroadcastController as AdminTelegramBroadcastController;
use App\Http\Controllers\Admin\WageringController as AdminWageringController;
use App\Http\Controllers\Admin\OperationsController as AdminOperationsController;
use App\Http\Controllers\Admin\PlayerReportController as AdminPlayerReportController;
use App\Http\Controllers\Admin\QuickSearchController as AdminQuickSearchController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\SportsHomeController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerDepositController;
use App\Http\Controllers\PlayerPromotionController;
use App\Http\Controllers\PlayerWithdrawalController;
use App\Http\Controllers\ProviderGameController;
use App\Http\Controllers\TelegramLoginController;
use App\Http\Controllers\TelegramWebAppAuthController;
use App\Http\Controllers\AgentPortalController;
use Illuminate\Support\Facades\Route;

// The sportsbook is the front page; the casino lobby keeps its route name so
// every lobby link, filter form and pager keeps working from /casino.
Route::get('/', [SportsHomeController::class, 'index'])->name('sportsbook');
Route::get('/casino', [LobbyController::class, 'index'])->name('home');
Route::redirect('/sportsbook', '/', 301);
Route::get('/promotions', [LobbyController::class, 'promotions'])->name('promotions');

// Operator-uploaded hero artwork, served from the database (see App\Models\SiteMedia).
Route::get('/media/hero/{slot}/{version}', [\App\Http\Controllers\MediaController::class, 'hero'])
    ->where(['slot' => 'desktop|mobile', 'version' => '[A-Za-z0-9_\-]{6,40}'])
    ->middleware('throttle:240,1')
    ->name('media.hero');

/*
 * Language switch. Stores the choice in a year-long cookie and returns the
 * player to the page they were on, so switching never loses their place.
 */
Route::get('/language/{locale}', function (string $locale) {
    abort_unless(\App\Http\Middleware\SetLocale::isSupported($locale), 404);

    return back()->withCookie(cookie()->forever(
        \App\Http\Middleware\SetLocale::COOKIE,
        $locale
    ));
})->name('language.switch');
Route::view('/about', 'pages.about')->name('about');
Route::view('/responsible-play', 'pages.responsible')->name('responsible');
Route::get('/telegram/login/{token}', TelegramLoginController::class)
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:20,1')
    ->name('telegram.login');

Route::get('/clear-deploy-cache', function (\Illuminate\Http\Request $request) {
    /*
     * This resets opcache and throws away every compiled view, route and config —
     * after which each worker recompiles the lot under whatever traffic is on the
     * site. That is exactly the deploy-time reset it exists for, and exactly what
     * an outsider must not be able to trigger at peak: unauthenticated, five calls
     * a minute of forced recompilation was a denial-of-service lever pointed at
     * ourselves. 404 rather than 403 when the token is absent or wrong, so the
     * route does not advertise its own existence.
     */
    $token = (string) config('app.deploy_cache_token');
    $given = $request->query('token');
    // is_string, not a cast: ?token[]=x arrives as an array, and casting that
    // raises an ErrorException — a 500 that tells a prober the route exists.
    abort_unless($token !== '' && is_string($given) && hash_equals($token, $given), 404);

    \Illuminate\Support\Facades\Artisan::call('view:clear');
    \Illuminate\Support\Facades\Artisan::call('route:clear');
    \Illuminate\Support\Facades\Artisan::call('config:clear');
    if (function_exists('opcache_reset')) {
        opcache_reset();
    }
    return response()->json(['ok' => true, 'cleared' => ['views', 'routes', 'config', 'opcache']]);
})->middleware('throttle:5,1');

Route::post('/telegram/webapp-auth', TelegramWebAppAuthController::class)
    ->middleware('throttle:30,1')
    ->name('telegram.webapp-auth');

Route::middleware('guest')->group(function () {
    // `login` and `register` are named limiters (App\Support\AuthRateLimits):
    // per-IP and per-account ceilings that answer with the form and a message
    // instead of a bare 429, and log the refusal.
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

// Email confirmation. The link is signed and time-limited; it works signed out
// because people open mail on a different device from the one they signed up on.
Route::get('/email/verify/{id}/{hash}', [\App\Http\Controllers\EmailVerificationController::class, 'verify'])
    ->where(['id' => '[0-9]+', 'hash' => '[a-f0-9]{40}'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('verification.verify');
Route::post('/email/verification-notification', [\App\Http\Controllers\EmailVerificationController::class, 'resend'])
    ->middleware(['auth', 'throttle:verification'])
    ->name('verification.send');

Route::prefix('agent')->name('agent.')->group(function () {
    Route::get('/login', [AgentPortalController::class, 'showLogin'])->name('login');
    Route::post('/login', [AgentPortalController::class, 'login'])->middleware('throttle:10,1')->name('login.store');
    Route::post('/logout', [AgentPortalController::class, 'logout'])->name('logout');

    // Presence is recorded for every authenticated agent request, so a player
    // choosing who to deposit with can see who is actually at their desk.
    Route::middleware(['auth:agent', \App\Http\Middleware\TrackAgentPresence::class])->group(function () {
        Route::get('/', [AgentPortalController::class, 'dashboard'])->name('dashboard');
        Route::post('/telegram/generate-code', [AgentPortalController::class, 'generateTelegramLinkCode'])->name('telegram.generate-code');
        Route::delete('/telegram', [AgentPortalController::class, 'unlinkTelegram'])->name('telegram.unlink');
        Route::get('/bank-info', [AgentPortalController::class, 'bankInfo'])->name('bank-info');
        Route::post('/bank-info', [AgentPortalController::class, 'storeBankAccount'])->name('bank-info.store');
        Route::patch('/bank-info/{account}/toggle', [AgentPortalController::class, 'toggleBankAccount'])->name('bank-info.toggle');
        Route::get('/deposit-order', [AgentPortalController::class, 'depositOrders'])->name('deposit-orders');
        Route::patch('/deposit-order/{order}', [AgentPortalController::class, 'processDeposit'])->name('deposit-orders.process');
        Route::redirect('/deposit-orders', '/agent/deposit-order');
        Route::get('/customers', [AgentPortalController::class, 'customers'])->name('customers');
        Route::get('/reports', [AgentPortalController::class, 'reports'])->name('reports');
        Route::get('/reports/export/{type}', [AgentPortalController::class, 'exportReport'])->middleware('throttle:10,1')->name('reports.export');
        Route::get('/withdrawals', [AgentPortalController::class, 'withdrawals'])->name('withdrawals');
        Route::post('/withdrawals/manual', [AgentPortalController::class, 'manualWithdrawal'])->name('withdrawals.manual');
        Route::patch('/withdrawals/{order}', [AgentPortalController::class, 'processWithdrawal'])->name('withdrawals.process');
        Route::get('/direct-deposit', [AgentPortalController::class, 'directDeposit'])->name('direct-deposit');
        Route::post('/direct-deposit', [AgentPortalController::class, 'storeDirectDeposit'])->name('direct-deposit.store');
        Route::get('/credit', [AgentPortalController::class, 'creditTransfer'])->name('credit-transfer');
        Route::post('/credit', [AgentPortalController::class, 'storeCreditTransfer'])->name('credit-transfer.store');
        Route::redirect('/credit-transfer', '/agent/credit');
        Route::get('/profile', [AgentPortalController::class, 'profile'])->name('profile');
        Route::put('/profile', [AgentPortalController::class, 'updateProfile'])->name('profile.update');
    });
});

Route::middleware('auth')->prefix('player')->name('player.')->group(function () {
    // Live balance for the topbar. A sportsbook stake is settled server-to-server
    // by WinHouse, so the page that is open has no idea the wallet moved — without
    // this the pill keeps showing the pre-bet figure until the next full reload,
    // which reads as "my bet was not taken".
    Route::get('/balance', [PlayerController::class, 'balance'])
        ->middleware('throttle:120,1')
        ->name('balance');
    Route::get('/wallet', [PlayerController::class, 'wallet'])->name('wallet');
    Route::get('/tournament', [\App\Http\Controllers\TournamentController::class, 'index'])->name('tournament');
    Route::post('/wallet/deposits', [PlayerDepositController::class, 'store'])->middleware('throttle:10,1')->name('deposits.store');
    Route::patch('/wallet/deposits/{order}/cancel', [PlayerDepositController::class, 'cancel'])->name('deposits.cancel');
    Route::post('/wallet/withdrawals', [PlayerWithdrawalController::class, 'store'])->middleware('throttle:10,1')->name('withdrawals.store');
    Route::patch('/wallet/withdrawals/{order}/cancel', [PlayerWithdrawalController::class, 'cancel'])->name('withdrawals.cancel');
    // Rating the agent who settled an order. Rate-limited: it writes a row, and a
    // held-down button should not be able to hammer the uniqueness constraint.
    Route::post('/orders/{order}/rating', [\App\Http\Controllers\AgentRatingController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('orders.rate');
    Route::get('/profile', [PlayerController::class, 'profile'])->name('profile');
    // Connect the account to the Telegram bot. Status is polled while the player is in Telegram;
    // disconnecting asks for the password again.
    Route::get('/telegram', [\App\Http\Controllers\TelegramConnectController::class, 'show'])->middleware('throttle:30,1')->name('telegram.show');
    Route::get('/telegram/status', [\App\Http\Controllers\TelegramConnectController::class, 'status'])->middleware('throttle:90,1')->name('telegram.status');
    Route::post('/telegram/unlink', [\App\Http\Controllers\TelegramConnectController::class, 'unlink'])->middleware('throttle:5,1')->name('telegram.unlink');
});

// Public, and deliberately outside the auth group: a signed-out visitor sees the
// prediction card on the lobby, so the crests on it have to load for them too.
Route::get('/predictions/{pool}/crest/{side}', [\App\Http\Controllers\PredictionController::class, 'crest'])
    ->where('side', 'home|away')
    ->name('predictions.crest');

Route::middleware('auth')->group(function () {
    Route::get('/deposit-bonus', [PlayerPromotionController::class, 'depositBonus'])->name('deposit-bonus');
    Route::post('/deposit-bonus/claim', [PlayerPromotionController::class, 'claimDepositBonus'])->name('deposit-bonus.claim');
    Route::get('/shamo', [PlayerPromotionController::class, 'shamo'])->name('shamo');
    Route::post('/shamo/{campaign}/join', [PlayerPromotionController::class, 'joinShamo'])->name('shamo.join');
    Route::post('/predictions/{pool}/join', [\App\Http\Controllers\PredictionController::class, 'join'])
        ->middleware('throttle:20,1')
        ->name('predictions.join');
    Route::post('/shamo/participations/{participation}/claim', [PlayerPromotionController::class, 'claimShamo'])->name('shamo.claim');
});

Route::get('/games/{game:slug}', [GameController::class, 'show'])->name('games.show');
Route::post('/games/{game:slug}/play', [GameController::class, 'play'])
    ->middleware('auth')
    ->name('games.play');
Route::post('/games/{game:slug}/provider/launch', [ProviderGameController::class, 'launch'])
    ->middleware('auth')
    ->name('games.provider.launch');
// Launching is a POST, but a guest who presses Play is sent to the login screen with this
// URL remembered as their destination, and the redirect after signing in is a GET — which
// answered a 405 error page instead of the game. Land them on the game page instead.
// It must answer exactly as the game page does, including the 404: resolving the
// slug and redirecting unconditionally told an anonymous visitor which games exist
// by status code alone, which maps the hidden QTech catalogue before go-live.
Route::get('/games/{game:slug}/provider/launch', function (\App\Models\Game $game) {
    abort_unless($game->isVisibleTo(auth()->user()), 404);

    return redirect()->route('games.show', $game);
})->name('games.provider.launch.get');
Route::post('/games/provider/close-session', [ProviderGameController::class, 'closeSession'])
    ->middleware('auth')
    ->name('games.provider.close-session');

Route::prefix('admin')->name('admin.')->middleware(['auth', 'admin', 'audit.admin'])->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('/operations', [AdminOperationsController::class, 'index'])->name('operations.index');
    Route::get('/online-players', [\App\Http\Controllers\Admin\OnlinePlayersController::class, 'index'])->name('online-players');
    Route::get('/operations/audit', [AdminOperationsController::class, 'audit'])->name('operations.audit');
    Route::get('/operations/reconciliation', [AdminOperationsController::class, 'reconciliation'])->name('operations.reconciliation');
    Route::get('/administrators', [AdminAdministratorController::class, 'index'])->name('administrators.index');
    Route::post('/administrators', [AdminAdministratorController::class, 'store'])->name('administrators.store');
    Route::patch('/administrators/{user}/toggle', [AdminAdministratorController::class, 'toggle'])->name('administrators.toggle');
    Route::get('/members', [AdminMemberController::class, 'index'])->name('members.index');
    Route::get('/members/export', [AdminMemberController::class, 'export'])->middleware('throttle:10,1')->name('members.export');
    Route::post('/members/import', [AdminMemberController::class, 'import'])->name('members.import');
    Route::get('/members/{user}', [AdminMemberController::class, 'show'])->name('members.show');
    Route::patch('/members/{user}', [AdminMemberController::class, 'update'])->name('members.update');
    Route::patch('/members/{user}/status', [AdminMemberController::class, 'status'])->name('members.status');
    Route::post('/members/{user}/deposit', [AdminMemberController::class, 'deposit'])->name('members.deposit');
    Route::patch('/members/{user}/password', [AdminMemberController::class, 'resetPassword'])->name('members.password');
    Route::delete('/members/{user}', [AdminMemberController::class, 'destroy'])->name('members.destroy');
    Route::patch('/members/{user}/restore', [AdminMemberController::class, 'restore'])->withTrashed()->name('members.restore');
    Route::delete('/members/{user}/purge', [AdminMemberController::class, 'purge'])->withTrashed()->name('members.purge');
    Route::get('/quick-search', AdminQuickSearchController::class)->middleware('throttle:120,1')->name('quick-search');
    Route::get('/player-report', [AdminPlayerReportController::class, 'index'])->name('players.report');
    Route::get('/player-report/export', [AdminPlayerReportController::class, 'export'])->middleware('throttle:10,1')->name('players.report.export');
    Route::get('/rounds', [AdminRoundController::class, 'index'])->name('rounds.index');
    Route::get('/transactions', [AdminTransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/export', [AdminTransactionController::class, 'export'])->middleware('throttle:10,1')->name('transactions.export');
    Route::get('/casino/report', [AdminCasinoReportController::class, 'index'])->name('casino.report');
    Route::get('/casino/transactions', [AdminCasinoTransactionController::class, 'index'])->name('casino.transactions');
    // Sportsbook tickets from WinHouse, with a printable receipt (SportsTicketController).
    Route::get('/sports/tickets', [AdminSportsTicketController::class, 'index'])->name('sports.tickets');
    Route::get('/sports/tickets/{ticket}/print', [AdminSportsTicketController::class, 'print'])->where('ticket', '[A-Za-z0-9_.:\-]{1,80}')->name('sports.tickets.print');
    Route::get('/payment-gateways', [AdminPaymentGatewayController::class, 'index'])->name('payment-gateways.index');
    Route::post('/payment-gateways', [AdminPaymentGatewayController::class, 'store'])->name('payment-gateways.store');
    Route::patch('/payment-gateways/{gateway}/toggle', [AdminPaymentGatewayController::class, 'toggle'])->name('payment-gateways.toggle');
    Route::delete('/payment-gateways/{gateway}', [AdminPaymentGatewayController::class, 'destroy'])->name('payment-gateways.destroy');
    Route::get('/agents', [AdminAgentController::class, 'index'])->name('agents.index');
    Route::get('/agents/create', [AdminAgentController::class, 'create'])->name('agents.create');
    Route::post('/agents', [AdminAgentController::class, 'store'])->name('agents.store');
    Route::patch('/agents/{agent}/toggle', [AdminAgentController::class, 'toggle'])->name('agents.toggle');
    // Refused once an agent has handled money — see the controller. Deleting is
    // for an agent created by mistake; Disable is for one being stood down.
    Route::delete('/agents/{agent}', [AdminAgentController::class, 'destroy'])->name('agents.destroy');
    Route::patch('/agents/{agent}/balance', [AdminAgentController::class, 'updateBalance'])->name('agents.balance');
    Route::get('/agent-bank-info', [AdminAgentController::class, 'bankInfo'])->name('agents.bank-info');
    Route::post('/agent-bank-info', [AdminAgentController::class, 'storeBankAccount'])->name('agents.bank-info.store');
    Route::patch('/agent-bank-info/{account}/toggle', [AdminAgentController::class, 'toggleBankAccount'])->name('agents.bank-info.toggle');
    Route::delete('/agent-bank-info/{account}', [AdminAgentController::class, 'destroyBankAccount'])->name('agents.bank-info.destroy');
    Route::get('/agent-withdrawals', [AdminAgentController::class, 'withdrawals'])->name('agents.withdrawals');
    Route::get('/agent-reports', [AdminAgentController::class, 'reports'])->name('agents.reports');
    Route::get('/agent-reports/export', [AdminAgentController::class, 'exportReports'])->middleware('throttle:10,1')->name('agents.reports.export');
    Route::get('/agent-orders', [AdminAgentController::class, 'orders'])->name('agents.orders');
    Route::patch('/agent-orders/{order}/status', [AdminAgentController::class, 'updateOrderStatus'])->name('agents.orders.status');
    Route::patch('/agent-orders/{order}/assign', [AdminAgentController::class, 'assignOrder'])->name('agents.orders.assign');
    Route::get('/agent-orders/{order}/payout-proof', [AdminAgentController::class, 'payoutProof'])->name('agents.orders.payout-proof');
    Route::get('/game-engine', [AdminGameEngineController::class, 'index'])->name('game-engine.index');
    Route::post('/game-engine/sync', [AdminGameEngineController::class, 'sync'])->name('game-engine.sync');
    Route::post('/game-engine/sync-qtech', [AdminGameEngineController::class, 'syncQtech'])->name('game-engine.sync-qtech');
    Route::post('/game-engine/providers', [AdminGameEngineController::class, 'storeProvider'])->name('game-engine.providers.store');
    Route::put('/game-engine/providers/{provider}', [AdminGameEngineController::class, 'updateProvider'])->name('game-engine.providers.update');
    Route::patch('/game-engine/providers/{provider}/toggle', [AdminGameEngineController::class, 'toggleProvider'])->name('game-engine.providers.toggle');
    Route::post('/game-engine/categories', [AdminGameEngineController::class, 'storeCategory'])->name('game-engine.categories.store');
    Route::put('/game-engine/categories/{category}', [AdminGameEngineController::class, 'updateCategory'])->name('game-engine.categories.update');
    Route::patch('/game-engine/categories/{category}/toggle', [AdminGameEngineController::class, 'toggleCategory'])->name('game-engine.categories.toggle');
    Route::post('/game-engine/lobbies', [AdminGameEngineController::class, 'storeLobby'])->name('game-engine.lobbies.store');
    Route::put('/game-engine/lobbies/{lobby}', [AdminGameEngineController::class, 'updateLobby'])->name('game-engine.lobbies.update');
    Route::patch('/game-engine/lobbies/{lobby}/toggle', [AdminGameEngineController::class, 'toggleLobby'])->name('game-engine.lobbies.toggle');
    Route::post('/game-engine/games', [AdminGameEngineController::class, 'storeGame'])->name('game-engine.games.store');
    Route::get('/game-engine/games/{game}/edit', [AdminGameEngineController::class, 'editGame'])->name('game-engine.games.edit');
    Route::put('/game-engine/games/{game}', [AdminGameEngineController::class, 'updateGame'])->name('game-engine.games.update');
    Route::delete('/game-engine/games/{game}', [AdminGameEngineController::class, 'destroyGame'])->name('game-engine.games.destroy');
    Route::patch('/game-engine/games/{game}/toggle', [AdminGameEngineController::class, 'toggleGame'])->name('game-engine.games.toggle');
    Route::get('/promotions', [AdminPromotionController::class, 'index'])->name('promotions.index');
    Route::post('/promotions', [AdminPromotionController::class, 'store'])->name('promotions.store');
    Route::patch('/promotions/{promotion}/toggle', [AdminPromotionController::class, 'toggle'])->name('promotions.toggle');
    Route::delete('/promotions/{promotion}', [AdminPromotionController::class, 'destroy'])->name('promotions.destroy');
    Route::get('/prediction-pools', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'index'])->name('predictions.index');
    Route::post('/prediction-pools', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'store'])->name('predictions.store');
    Route::put('/prediction-pools/{pool}', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'update'])->name('predictions.update');
    Route::patch('/prediction-pools/{pool}/status', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'status'])->name('predictions.status');
    Route::post('/prediction-pools/{pool}/settle', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'settle'])->name('predictions.settle');
    Route::get('/prediction-pools/{pool}/entries', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'entries'])->name('predictions.entries');
    Route::delete('/prediction-pools/{pool}', [\App\Http\Controllers\Admin\PredictionPoolController::class, 'destroy'])->name('predictions.destroy');
    Route::get('/promotion/wagering-config', [AdminWageringController::class, 'index'])->name('wagering.index');
    Route::post('/promotion/wagering-config/policies', [AdminWageringController::class, 'storePolicy'])->name('wagering.policies.store');
    Route::put('/promotion/wagering-config/policies/{policy}', [AdminWageringController::class, 'updatePolicy'])->name('wagering.policies.update');
    Route::delete('/promotion/wagering-config/policies/{policy}', [AdminWageringController::class, 'destroyPolicy'])->name('wagering.policies.destroy');
    Route::get('/promotion/wagering-config/deposit-bonuses/create', [AdminWageringController::class, 'createDeposit'])->name('wagering.deposits.create');
    Route::post('/promotion/wagering-config/deposit-bonuses', [AdminWageringController::class, 'storeDeposit'])->name('wagering.deposits.store');
    Route::patch('/promotion/wagering-config/deposit-bonuses/{config}/toggle', [AdminWageringController::class, 'toggleDeposit'])->name('wagering.deposits.toggle');
    Route::delete('/promotion/wagering-config/deposit-bonuses/{config}', [AdminWageringController::class, 'destroyDeposit'])->name('wagering.deposits.destroy');
    Route::get('/shamo', [AdminShamoController::class, 'index'])->name('shamo.index');
    Route::post('/shamo', [AdminShamoController::class, 'store'])->name('shamo.store');
    Route::patch('/shamo/{campaign}/status', [AdminShamoController::class, 'status'])->name('shamo.status');
    Route::patch('/shamo/participations/{participation}/award', [AdminShamoController::class, 'award'])->name('shamo.award');
    Route::get('/settings', [AdminSettingController::class, 'index'])->name('settings.index');
    Route::put('/settings', [AdminSettingController::class, 'update'])->name('settings.update');
    // No shell on this host, and this brand's build command does not migrate.
    Route::post('/settings/migrations/run', [AdminSettingController::class, 'runMigrations'])->name('settings.migrations.run');
    // Empties the books before the platform opens. Destructive and deliberate:
    // the operator ticks what goes and types the confirmation word.
    Route::post('/settings/go-live-reset', [AdminSettingController::class, 'goLiveReset'])->name('settings.go-live-reset');
    // The lobby hero artwork. Its own POST route because the settings form
    // spoofs PUT and cannot carry a file upload for a different section.
    Route::post('/settings/hero', [AdminSettingController::class, 'updateHero'])->name('settings.hero');
    // Proves the SMTP account saved on the Security tab by sending to the signed-in administrator.
    Route::post('/settings/mail/test', [AdminSettingController::class, 'testMail'])->middleware('throttle:5,1')->name('settings.mail.test');
    Route::post('/settings/qtech/test', [AdminSettingController::class, 'testQtech'])->name('settings.qtech.test');
    Route::post('/settings/winhouse/test', [AdminSettingController::class, 'testWinhouse'])->name('settings.winhouse.test');
    Route::post('/settings/qtech/test-credentials', [AdminSettingController::class, 'issueQtechTestCredentials'])->name('settings.qtech.credentials');
    Route::post('/settings/qtech/repair-test-players', [AdminSettingController::class, 'repairQtechTestPlayers'])->name('settings.qtech.repair');
    Route::get('/telegram/broadcast-bot', [AdminTelegramBroadcastController::class, 'index'])->name('telegram.index');
    Route::get('/telegram/templates/create', [AdminTelegramBroadcastController::class, 'createTemplate'])->name('telegram.templates.create');
    Route::post('/telegram/templates', [AdminTelegramBroadcastController::class, 'storeTemplate'])->name('telegram.templates.store');
    Route::get('/telegram/templates/{template}/edit', [AdminTelegramBroadcastController::class, 'editTemplate'])->name('telegram.templates.edit');
    Route::put('/telegram/templates/{template}', [AdminTelegramBroadcastController::class, 'updateTemplate'])->name('telegram.templates.update');
    Route::delete('/telegram/templates/{template}', [AdminTelegramBroadcastController::class, 'destroyTemplate'])->name('telegram.templates.destroy');
    Route::get('/telegram/broadcasts/create', [AdminTelegramBroadcastController::class, 'createBroadcast'])->name('telegram.broadcasts.create');
    Route::post('/telegram/broadcasts', [AdminTelegramBroadcastController::class, 'storeBroadcast'])->name('telegram.broadcasts.store');
    Route::get('/telegram/broadcasts/{broadcast}/edit', [AdminTelegramBroadcastController::class, 'editBroadcast'])->name('telegram.broadcasts.edit');
    Route::put('/telegram/broadcasts/{broadcast}', [AdminTelegramBroadcastController::class, 'updateBroadcast'])->name('telegram.broadcasts.update');
    Route::delete('/telegram/broadcasts/{broadcast}', [AdminTelegramBroadcastController::class, 'destroyBroadcast'])->name('telegram.broadcasts.destroy');
    Route::post('/telegram/broadcasts/{broadcast}/send', [AdminTelegramBroadcastController::class, 'sendBroadcast'])->name('telegram.broadcasts.send');
    Route::post('/telegram/broadcasts/{broadcast}/retry', [AdminTelegramBroadcastController::class, 'retryBroadcast'])->name('telegram.broadcasts.retry');
    Route::post('/telegram/connection', [AdminTelegramBroadcastController::class, 'configureConnection'])->name('telegram.connection.configure');
    Route::post('/telegram/webhook/connect', [AdminTelegramBroadcastController::class, 'connectWebhook'])->name('telegram.webhook.connect');
    Route::get('/module/{module}', [AdminDashboardController::class, 'placeholder'])->name('module');
});
