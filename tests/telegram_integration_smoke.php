<?php

use App\Http\Controllers\Admin\TelegramBroadcastController;
use App\Http\Controllers\AgentPortalController;
use App\Http\Controllers\TelegramLoginController;
use App\Http\Controllers\TelegramWebhookController;
use App\Models\Agent;
use App\Models\AgentBankAccount;
use App\Models\AgentOrder;
use App\Models\PaymentGateway;
use App\Models\PlatformSetting;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastDelivery;
use App\Models\TelegramLoginToken;
use App\Models\TelegramSubscriber;
use App\Models\TelegramTemplate;
use App\Models\User;
use App\Services\TelegramBotService;
use App\Services\TelegramDepositService;
use App\Services\TelegramPlayerService;
use App\Services\AgentLedgerService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

DB::beginTransaction();

try {
    config([
        'services.telegram.bot_token' => 'test-token',
        'services.telegram.webhook_secret' => 'test-secret',
    ]);
    Http::fake([
        'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
    ]);

    $telegram = $app->make(TelegramBotService::class);
    $players = $app->make(TelegramPlayerService::class);
    $deposits = $app->make(TelegramDepositService::class);
    $webhook = $app->make(TelegramWebhookController::class);

    $storedToken = '123456789:abcdefghijklmnopqrstuvwxyz_ABCDE';
    $telegram->configureToken($storedToken);
    $encryptedToken = PlatformSetting::query()->find('telegram_bot_token_encrypted')?->value;
    if (! $encryptedToken || $encryptedToken === $storedToken || ! ($telegram->configurationSources()['database'] ?? false)) {
        throw new RuntimeException('Telegram encrypted database token fallback was not stored safely.');
    }
    $recordedBeforeWebhook = count(Http::recorded());

    $telegramRequest = static function (string $text, int $telegramUserId = 90000002): Request {
        $request = Request::create('/api/integrations/telegram/webhook', 'POST', [
            'message' => [
                'text' => $text,
                'from' => ['id' => $telegramUserId, 'username' => 'smoke_test_'.$telegramUserId, 'first_name' => 'Smoke'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
            ],
        ]);
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'test-secret');

        return $request;
    };

    $contactRequest = static function (string $phone, int $telegramUserId, ?int $contactUserId = null): Request {
        $request = Request::create('/api/integrations/telegram/webhook', 'POST', [
            'message' => [
                'from' => ['id' => $telegramUserId, 'username' => 'smoke_test_'.$telegramUserId, 'first_name' => 'Smoke'],
                'chat' => ['id' => $telegramUserId, 'type' => 'private'],
                'contact' => [
                    'phone_number' => $phone,
                    'first_name' => 'Smoke',
                    'user_id' => $contactUserId ?? $telegramUserId,
                ],
            ],
        ]);
        $request->headers->set('X-Telegram-Bot-Api-Secret-Token', 'test-secret');

        return $request;
    };

    $lastTelegramPayload = static function (): string {
        $sent = Http::recorded()->last()[0];
        $payload = '';
        try {
            $payload .= (string) json_encode($sent->data());
        } catch (\Throwable) {
        }
        try {
            $payload .= (string) $sent->body();
        } catch (\Throwable) {
        }

        return $payload;
    };

    // A member who registered on the website before ever opening the bot. The number is
    // stored in the local 09… spelling to prove format-independent matching.
    $existingLocalPhone = '09'.random_int(10000000, 99999999);
    $existingMember = User::create([
        'name' => 'Existing Member',
        'first_name' => 'Existing',
        'last_name' => 'Member',
        'phone' => $existingLocalPhone,
        'email' => 'existing.member.'.uniqid().'@lucyplay.local',
        'password' => 'password123',
        'role' => User::ROLE_PLAYER,
        'credits' => 250,
        'active' => true,
    ]);

    $response = $webhook($telegramRequest('/start', 90000001), $telegram, $players, $deposits);
    $active = TelegramSubscriber::query()->where('telegram_user_id', 90000001)->value('active');
    if ($response->status() !== 200 || ! $active || count(Http::recorded()) < $recordedBeforeWebhook + 1) {
        throw new RuntimeException('Telegram webhook smoke test failed.');
    }
    $existingSubscriber = TelegramSubscriber::query()->where('telegram_user_id', 90000001)->firstOrFail();
    if ($existingSubscriber->user_id !== null || $existingSubscriber->pending_action !== 'await_phone') {
        throw new RuntimeException('The bot must wait for a shared phone number before opening a player account.');
    }

    $webhook($telegramRequest('Play Games', 90000001), $telegram, $players, $deposits);
    $existingSubscriber->refresh();
    if ($existingSubscriber->user_id !== null) {
        throw new RuntimeException('Play Games created a player account before the phone number was shared.');
    }
    if (! str_contains($lastTelegramPayload(), 'request_contact')) {
        throw new RuntimeException('The bot did not send the mandatory share-phone-number button.');
    }

    // A forwarded contact card belongs to somebody else and must be refused.
    $webhook($contactRequest('+251900000000', 90000003, 90000009), $telegram, $players, $deposits);
    $forwarded = TelegramSubscriber::query()->where('telegram_user_id', 90000003)->firstOrFail();
    if ($forwarded->phone !== null || $forwarded->user_id !== null) {
        throw new RuntimeException('A forwarded contact card was accepted as the sender phone number.');
    }

    // The same number in international spelling must resolve to the existing member.
    $memberCountBeforeLink = User::query()->count();
    $webhook($contactRequest('+251'.substr($existingLocalPhone, 1), 90000001), $telegram, $players, $deposits);
    $existingSubscriber->refresh();
    if ($existingSubscriber->user_id !== $existingMember->id) {
        throw new RuntimeException('The shared contact was not matched to the member that already owns this phone number.');
    }
    if (User::query()->count() !== $memberCountBeforeLink) {
        throw new RuntimeException('Sharing a known phone number registered a duplicate player account.');
    }
    $existingMember->refresh();
    if (! $existingMember->contact_confirmed || (float) $existingMember->credits !== 250.0) {
        throw new RuntimeException('Linking an existing member changed the wallet or left the contact unconfirmed.');
    }
    if ($existingMember->walletTransactions()->where('source_type', 'telegram_registration')->exists()) {
        throw new RuntimeException('An existing member was granted a second welcome balance through Telegram.');
    }

    // A brand new player registers with the phone number Telegram verified.
    $newPhone = '2519'.random_int(10000000, 99999999);
    $webhook($telegramRequest('/start'), $telegram, $players, $deposits);
    $webhook($contactRequest('+'.$newPhone, 90000002), $telegram, $players, $deposits);

    $subscriber = TelegramSubscriber::query()->where('telegram_user_id', 90000002)->firstOrFail();
    if ($subscriber->phone !== $newPhone || $subscriber->pending_action !== null) {
        throw new RuntimeException('The verified Telegram phone number was not stored on the subscriber.');
    }
    if (! $subscriber->user_id || $subscriber->user->phone !== $newPhone || ! $subscriber->user->contact_confirmed) {
        throw new RuntimeException('Telegram registration did not create a member carrying the verified phone number.');
    }
    if ($subscriber->user->walletTransactions()->where('source_type', 'telegram_registration')->count() !== 1) {
        throw new RuntimeException('Telegram registration did not create the welcome wallet ledger entry.');
    }

    $webhook($telegramRequest('Play Games'), $telegram, $players, $deposits);
    $subscriber->refresh();
    $playRequestPayload = Http::recorded()->last()[0]->data();
    $miniAppUrl = data_get($playRequestPayload, 'reply_markup.inline_keyboard.0.0.web_app.url');
    if ($subscriber->loginTokens()->whereNull('used_at')->count() !== 1) {
        throw new RuntimeException('Telegram Play Games did not hand the linked player exactly one live login token, found '
            .$subscriber->loginTokens()->whereNull('used_at')->count().' of '.$subscriber->loginTokens()->count().'.');
    }
    if (! str_starts_with((string) $miniAppUrl, 'http')) {
        throw new RuntimeException('Telegram Play Games did not send a mini app URL, got '.var_export($miniAppUrl, true).'.');
    }

    $depositGateway = PaymentGateway::query()->where('active', true)->where('supports_deposit', true)->firstOrFail();
    $depositAgent = Agent::create([
        'first_name' => 'Telegram',
        'last_name' => 'Agent',
        'nickname' => 'telegram_agent_'.uniqid(),
        'phone' => '2519'.random_int(10000000, 99999999),
        'password' => 'password123',
        'balance' => 5000,
        'active' => true,
    ]);
    $depositAccount = AgentBankAccount::create([
        'agent_id' => $depositAgent->id,
        'payment_gateway_id' => $depositGateway->id,
        'account_name' => 'Telegram Agent',
        'account_number' => $depositAgent->phone,
        'active' => true,
    ]);
    $playerBalanceBeforeDeposit = (float) $subscriber->user->credits;

    $webhook($telegramRequest('Deposit'), $telegram, $players, $deposits);
    $subscriber->refresh();
    if ($subscriber->pending_action !== 'deposit_amount') {
        throw new RuntimeException('The Deposit button did not start the amount conversation.');
    }

    $webhook($telegramRequest('50.00'), $telegram, $players, $deposits);
    $subscriber->refresh();
    if ($subscriber->pending_action !== 'deposit_bank') {
        throw new RuntimeException('The valid deposit amount did not present the agent bank choices.');
    }

    $bankOption = collect(data_get($subscriber->pending_payload, 'options', []))->firstWhere('bank_account_id', $depositAccount->id);
    if (! $bankOption) {
        throw new RuntimeException('The configured agent bank account was not offered to the Telegram player.');
    }

    $webhook($telegramRequest($bankOption['label']), $telegram, $players, $deposits);
    $subscriber->refresh();
    $depositOrder = AgentOrder::query()->where('user_id', $subscriber->user_id)->where('type', 'deposit')->latest('id')->firstOrFail();
    if ($subscriber->pending_action !== 'deposit_reference' || $depositOrder->agent_id !== $depositAgent->id || $depositOrder->account_number !== $depositAccount->account_number) {
        throw new RuntimeException('Telegram bank selection did not create the assigned pending deposit order.');
    }

    $reference = 'TG-SMOKE-'.strtoupper(uniqid());
    $webhook($telegramRequest($reference), $telegram, $players, $deposits);
    $subscriber->refresh();
    $depositOrder->refresh();
    if ($subscriber->pending_action !== null || $depositOrder->reference !== $reference || (float) $subscriber->user->fresh()->credits !== $playerBalanceBeforeDeposit) {
        throw new RuntimeException('Telegram reference submission did not preserve the pending, uncredited order state.');
    }

    Auth::guard('agent')->login($depositAgent);
    $approvalRequest = Request::create('/agent/deposit-order/'.$depositOrder->id, 'PATCH', ['status' => 'success']);
    $app->make(AgentPortalController::class)->processDeposit(
        $approvalRequest,
        $depositOrder,
        $telegram,
        $app->make(AgentLedgerService::class),
    );
    Auth::guard('agent')->logout();
    $depositOrder->refresh();
    if ($depositOrder->status !== 'success'
        || (float) $subscriber->user->fresh()->credits !== $playerBalanceBeforeDeposit + 50
        || (float) $depositAgent->fresh()->balance !== 4950.0
        || ! $subscriber->user->walletTransactions()->where('source_type', 'agent_order')->where('source_id', $depositOrder->id)->where('status', 'success')->exists()) {
        throw new RuntimeException('Agent approval did not atomically credit the Telegram player and debit the agent.');
    }

    $loginUrl = $players->createLoginLink($subscriber, 'lobby');
    $plainToken = basename(parse_url($loginUrl, PHP_URL_PATH));
    $loginRequest = Request::create('/telegram/login/'.$plainToken, 'GET');
    $loginRequest->setLaravelSession($app->make('session')->driver());
    $loginResponse = $app->make(TelegramLoginController::class)($loginRequest, $plainToken);
    if (! Auth::check() || Auth::id() !== $subscriber->user_id || $loginResponse->getTargetUrl() !== route('home')) {
        throw new RuntimeException('The one-time Telegram login did not authenticate the linked player.');
    }
    if (! TelegramLoginToken::query()->where('token_hash', hash('sha256', $plainToken))->whereNotNull('used_at')->exists()) {
        throw new RuntimeException('The one-time Telegram login token was not consumed.');
    }
    Auth::logout();

    $template = TelegramTemplate::create([
        'name' => 'Smoke template',
        'caption' => '<b>Safe mocked broadcast</b>',
        'buttons' => [['text' => 'Open', 'type' => 'url', 'value' => 'https://example.com']],
    ]);
    $broadcast = TelegramBroadcast::create([
        'telegram_template_id' => $template->id,
        'name' => 'Smoke broadcast',
        'caption' => $template->caption,
        'buttons' => $template->buttons,
        'status' => 'queued',
        'total_recipients' => 1,
    ]);
    TelegramBroadcastDelivery::create([
        'telegram_broadcast_id' => $broadcast->id,
        'telegram_subscriber_id' => $subscriber->id,
        'chat_id' => $subscriber->chat_id,
        'status' => 'pending',
    ]);
    $app->make(TelegramBroadcastController::class)->sendBroadcast($broadcast, $telegram);
    $broadcast->refresh();
    if ($broadcast->status !== 'completed' || $broadcast->sent_count !== 1 || $broadcast->failed_count !== 0) {
        throw new RuntimeException('Telegram immediate broadcast delivery smoke test failed.');
    }

    // Test Edit Broadcast live sync to Telegram
    $editRequest = Request::create('/admin/telegram/broadcasts/'.$broadcast->id, 'PUT', [
        'name' => 'Edited Broadcast Title',
        'caption' => '<b>Updated broadcast caption text</b>',
        'buttons' => [['text' => 'Play Now', 'type' => 'url', 'value' => 'https://example.com/play']],
    ]);
    $app->make(TelegramBroadcastController::class)->updateBroadcast($editRequest, $broadcast, $telegram);
    $broadcast->refresh();
    if ($broadcast->name !== 'Edited Broadcast Title' || $broadcast->caption !== '<b>Updated broadcast caption text</b>') {
        throw new RuntimeException('Telegram broadcast edit database update failed.');
    }
    $lastEditedTelegramRequest = Http::recorded()->last()[0]->url();
    if (! str_contains($lastEditedTelegramRequest, 'editMessageText') && ! str_contains($lastEditedTelegramRequest, 'editMessageCaption')) {
        throw new RuntimeException('Telegram broadcast edit live API sync failed.');
    }

    // Test Delete Broadcast live sync to Telegram
    $app->make(TelegramBroadcastController::class)->destroyBroadcast($broadcast, $telegram);
    if (TelegramBroadcast::query()->find($broadcast->id) !== null) {
        throw new RuntimeException('Telegram broadcast delete failed to remove record from database.');
    }
    $lastDeletedTelegramRequest = Http::recorded()->last()[0]->url();
    if (! str_contains($lastDeletedTelegramRequest, 'deleteMessage')) {
        throw new RuntimeException('Telegram broadcast delete live API sync failed.');
    }

    echo "Telegram deposit approval, broadcast sending, editing, and deletion smoke tests passed.\n";
} finally {
    Auth::guard('agent')->logout();
    Auth::logout();
    DB::rollBack();
}
