<?php

namespace App\Services;

use App\Exceptions\TelegramPhoneRequiredException;
use App\Models\PlatformSetting;
use App\Models\TelegramLoginToken;
use App\Models\TelegramSubscriber;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Repositories\UserRepository;
use App\Support\PhoneNumber;
use App\Support\Username;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramPlayerService
{
    /**
     * Plain password issued during this request, if a new account was just created. It is
     * never stored anywhere: the bot shows it once so the player can also sign in on a
     * browser with their phone number.
     */
    private ?string $issuedPassword = null;

    public function __construct(private readonly AccountMergeService $merges)
    {
    }

    /** The password issued by the last ensurePlayer() call, or null when none was created. */
    public function issuedPassword(): ?string
    {
        return $this->issuedPassword;
    }

    /**
     * Replace a player's password with a freshly generated one and return it in clear text
     * so the bot can show it. Used when a player loses the password we issued at signup.
     */
    public function reissuePassword(User $user): string
    {
        $password = $this->generatePassword();
        $user->forceFill(['password' => $password])->save();

        Log::info('A player password was reissued through the Telegram bot.', ['user_id' => $user->id]);

        return $password;
    }

    /**
     * Readable but unguessable: 10 characters from an alphabet with no look-alike glyphs, so
     * a player can retype it from their chat history without ambiguity.
     */
    private function generatePassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';

        for ($index = 0; $index < 10; $index++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        // Guarantee both cases and a digit regardless of how the draw went.
        return preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/\d/', $password)
            ? $password
            : $this->generatePassword();
    }

    /**
     * Store the contact card Telegram verified for this subscriber.
     * Only the subscriber's own contact is accepted by the caller.
     */
    public function recordSharedPhone(TelegramSubscriber $subscriber, ?string $rawPhone): TelegramSubscriber
    {
        $normalized = PhoneNumber::normalize($rawPhone);

        if ($normalized === null) {
            throw new TelegramPhoneRequiredException('That phone number could not be read. Tap the button below to share your contact again.');
        }

        $subscriber->forceFill([
            'phone' => $normalized,
            'phone_shared_at' => now(),
        ])->save();

        return $subscriber;
    }

    /**
     * Resolve the player behind a subscriber. A verified phone number is mandatory:
     * when the number already belongs to a member the accounts are linked instead
     * of registering the same person twice.
     */
    public function ensurePlayer(TelegramSubscriber $subscriber): User
    {
        $this->issuedPassword = null;

        // Folding a duplicate account happens BEFORE the linking transaction,
        // never inside it. The merge rewrites the player's entire history; run
        // under this method's subscriber lock it held both user rows locked for
        // the whole rewrite, and every wallet callback for that player parked a
        // worker on the row lock behind it — measured freezing the whole site
        // whenever a duplicate-phone player started playing. Outside, the merge
        // takes its own short locks, and a failure downgrades to "play on the
        // un-merged wallet until the next login retries it", not a failed login.
        $this->foldDuplicateAccount($subscriber);

        return DB::transaction(function () use ($subscriber): User {
            $locked = TelegramSubscriber::query()->lockForUpdate()->findOrFail($subscriber->id);

            if ($locked->user_id) {
                $user = User::query()->findOrFail($locked->user_id);

                $this->backfillMemberContact($user, $locked);

                return $this->attach($subscriber, $locked, $user);
            }

            $phone = PhoneNumber::normalize($locked->phone);
            if ($phone === null) {
                throw new TelegramPhoneRequiredException();
            }

            if ($existing = $this->findMemberByPhone($phone)) {
                $this->releasePreviousSubscriber($existing, $locked);
                $this->backfillMemberContact($existing, $locked);
                $locked->update(['user_id' => $existing->id]);

                Log::info('Telegram subscriber linked to the existing member that owns this phone number.', [
                    'telegram_subscriber_id' => $locked->id,
                    'telegram_user_id' => $locked->telegram_user_id,
                    'user_id' => $existing->id,
                ]);

                return $this->attach($subscriber, $locked, $existing);
            }

            $user = $this->registerMember($locked, $phone);
            $locked->update(['user_id' => $user->id]);

            return $this->attach($subscriber, $locked, $user);
        });
    }

    /**
     * Fold a bot-created wallet into the account that owns the phone number, so
     * the balance the player sees in Telegram and the one an administrator sees
     * are the same wallet. Runs lock-free checks first; the merge itself manages
     * its own locking. A failure is logged and swallowed — the player keeps
     * playing on the account they came in with and the next login retries.
     */
    private function foldDuplicateAccount(TelegramSubscriber $subscriber): void
    {
        $subscriber->refresh();

        if (! $subscriber->user_id || blank($subscriber->phone)) {
            return;
        }

        $user = User::query()->find($subscriber->user_id);
        if (! $user) {
            return;
        }

        try {
            $owner = $this->findMemberByPhone((string) $subscriber->phone);
        } catch (TelegramPhoneRequiredException) {
            // The number belongs to a staff or closed account; never touch the link.
            return;
        }

        if (! $owner || $owner->id === $user->id) {
            return;
        }

        try {
            $this->merges->merge($user, $owner);
        } catch (\Throwable $exception) {
            report($exception);
            Log::warning('Duplicate account merge failed; continuing with the linked account.', [
                'telegram_subscriber_id' => $subscriber->id,
                'duplicate_user_id' => $user->id,
                'survivor_user_id' => $owner->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Find the member that already owns this number. Historic rows store the number
     * in several spellings (0912…, 251912…, +251 912…) so every variant is checked.
     */
    public function findMemberByPhone(string $phone): ?User
    {
        $candidates = PhoneNumber::candidates($phone);

        if ($candidates === []) {
            return null;
        }

        // Archived accounts are included: their number stays reserved, and silently opening
        // a fresh account for a number an administrator closed would defeat the archive.
        $owner = User::withTrashed()
            ->whereIn('phone', $candidates)
            ->orderByRaw("CASE WHEN role = ? THEN 0 ELSE 1 END", [User::ROLE_PLAYER])
            ->orderBy('id')
            ->first();

        if (! $owner) {
            return null;
        }

        if ($owner->trashed()) {
            throw new TelegramPhoneRequiredException(
                'This phone number belongs to an account that was closed. Please contact support to reopen it.'
            );
        }

        if ($owner->role !== User::ROLE_PLAYER) {
            throw new TelegramPhoneRequiredException(
                'This phone number belongs to a staff account and cannot be used to play. Please contact support.'
            );
        }

        return $owner;
    }

    public function createLoginLink(TelegramSubscriber $subscriber, string $destination = 'lobby'): string
    {
        $user = $subscriber->user()->firstOrFail();
        $plainToken = Str::random(64);

        DB::transaction(function () use ($subscriber, $user, $plainToken, $destination): void {
            TelegramLoginToken::query()
                ->where('telegram_subscriber_id', $subscriber->id)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            TelegramLoginToken::create([
                'user_id' => $user->id,
                'telegram_subscriber_id' => $subscriber->id,
                'token_hash' => hash('sha256', $plainToken),
                'destination' => in_array($destination, ['lobby', 'deposit'], true) ? $destination : 'lobby',
                'expires_at' => now()->addHours(24),
            ]);
        });

        $baseUrl = rtrim(
            trim((string) config('services.telegram.app_url'))
                ?: $this->runtimeEnvironment('TELEGRAM_APP_URL')
                ?: $this->runtimeEnvironment('APP_URL')
                ?: (string) config('app.url'),
            '/'
        );

        return $baseUrl.route('telegram.login', ['token' => $plainToken], false);
    }

    private function registerMember(TelegramSubscriber $subscriber, string $phone): User
    {
        $firstName = trim((string) $subscriber->first_name);
        $lastName = trim((string) $subscriber->last_name);
        $displayName = trim($firstName.' '.$lastName);
        if ($displayName === '') {
            $displayName = $subscriber->username ? '@'.$subscriber->username : 'Telegram Player';
        }

        $welcomeBalance = (int) PlatformSetting::valueFor('welcome_credits', 5);
        // Issued in clear text once through the bot so the same account can be used on a
        // browser; only the hash is ever stored.
        $this->issuedPassword = $this->generatePassword();

        $user = UserRepository::create([
            'name' => Str::limit($displayName, 80, ''),
            // The Telegram handle is the player's own name for the account; the phone number
            // Telegram verified is the identity. No invented email address.
            'username' => $subscriber->username ?: $displayName,
            'first_name' => Str::limit($firstName ?: $displayName, 80, ''),
            'last_name' => Str::limit($lastName, 80, ''),
            'phone' => $phone,
            'password' => $this->issuedPassword,
            'role' => User::ROLE_PLAYER,
            'credits' => $welcomeBalance,
            'contact_confirmed' => true,
            'active' => true,
        ]);

        WalletTransaction::create([
            'ledger_key' => 'telegram-welcome-'.$subscriber->id,
            'user_id' => $user->id,
            'type' => 'welcome',
            'amount' => $welcomeBalance,
            'status' => 'success',
            'method' => 'telegram',
            'reason' => 'Telegram player welcome balance',
            'currency' => 'ETB',
            'source_type' => 'telegram_registration',
            'source_id' => $subscriber->id,
            'metadata' => ['telegram_user_id' => $subscriber->telegram_user_id, 'phone' => $phone],
        ]);

        return $user;
    }

    /**
     * A phone number identifies one person, so a re-used number moves the player
     * link to the Telegram account that just verified it.
     */
    private function releasePreviousSubscriber(User $user, TelegramSubscriber $current): void
    {
        TelegramSubscriber::query()
            ->where('user_id', $user->id)
            ->whereKeyNot($current->id)
            ->each(function (TelegramSubscriber $previous): void {
                Log::warning('Telegram player link moved to a newer Telegram account for the same phone number.', [
                    'previous_subscriber_id' => $previous->id,
                    'user_id' => $previous->user_id,
                ]);
                $previous->update(['user_id' => null]);
            });
    }

    /** Keep the member record aligned with the verified Telegram contact. */
    private function backfillMemberContact(User $user, TelegramSubscriber $subscriber): void
    {
        if (blank($subscriber->phone)) {
            return;
        }

        $updates = [];

        if (blank($user->phone)) {
            // Legacy Telegram accounts were created without a number. If the verified number
            // is already held by a different member the two records need a manual merge, so
            // the account the player is actively using is left untouched.
            $conflict = User::query()
                ->whereIn('phone', PhoneNumber::candidates($subscriber->phone))
                ->whereKeyNot($user->id)
                ->first();

            if ($conflict) {
                Log::warning('A verified Telegram phone number is already held by another member; both accounts were left untouched for manual review.', [
                    'telegram_subscriber_id' => $subscriber->id,
                    'linked_user_id' => $user->id,
                    'phone_owner_user_id' => $conflict->id,
                ]);
            } else {
                $updates['phone'] = $subscriber->phone;
            }
        }

        if (! $user->contact_confirmed) {
            $updates['contact_confirmed'] = true;
        }

        if (blank($user->username)) {
            $updates['username'] = Username::unique(
                [$subscriber->username, $user->name, trim($user->first_name.' '.$user->last_name)],
                $user->phone ?: $subscriber->phone,
                $user->id,
            );
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    private function attach(TelegramSubscriber $subscriber, TelegramSubscriber $locked, User $user): User
    {
        $subscriber->setRelation('user', $user);
        $subscriber->user_id = $locked->user_id ?: $user->id;
        $subscriber->phone = $locked->phone;
        $subscriber->syncOriginalAttributes(['user_id', 'phone']);

        return $user;
    }

    private function runtimeEnvironment(string $key): string
    {
        foreach ([
            $_SERVER[$key] ?? null,
            $_ENV[$key] ?? null,
            getenv($key),
        ] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }
}
