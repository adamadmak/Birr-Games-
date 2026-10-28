<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';
    public const ROLE_PLAYER = 'player';
    public const ADMIN_ROLE_SUPER = 'super_admin';
    public const ADMIN_ROLE_OPERATIONS = 'operations';
    public const ADMIN_ROLE_FINANCE = 'finance';
    public const ADMIN_ROLE_MARKETING = 'marketing';
    public const ADMIN_ROLE_SUPPORT = 'support';

    protected $fillable = [
        'name',
        'username',
        'first_name',
        'last_name',
        'email',
        'phone',
        'password',
        'role',
        'admin_role',
        'admin_permissions',
        'import_source_id',
        'member_type',
        'affiliate_id',
        'affiliate_provider',
        'hashed_username',
        'deposit_setting',
        'withdraw_setting',
        'credits',
        'withdrawable_balance',
        'withdrawal_cap',
        'transfer_cap',
        'deposit_cap',
        'contact_confirmed',
        'active',
        'email_verified_at',
        'imported_at',
        'registration_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'credits' => 'decimal:2',
            'withdrawable_balance' => 'decimal:2',
            'withdrawal_cap' => 'integer',
            'transfer_cap' => 'integer',
            'deposit_cap' => 'integer',
            'contact_confirmed' => 'boolean',
            'active' => 'boolean',
            'imported_at' => 'datetime',
            'admin_permissions' => 'array',
            'bigbang_provisioned_at' => 'datetime',
        ];
    }

    public function getFirstNameAttribute(?string $value): string
    {
        return $value ?: str($this->name)->before(' ')->toString();
    }

    /**
     * How this account is identified to staff and to itself: the handle for players,
     * the sign-in email for administrators.
     */
    public function handle(): string
    {
        if (filled($this->username)) {
            return '@'.$this->username;
        }

        return (string) ($this->isAdmin() ? $this->email : $this->phone);
    }

    public function getLastNameAttribute(?string $value): string
    {
        return $value ?? str($this->name)->after(' ')->toString();
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(GameRound::class);
    }

    public function bonusClaims(): HasMany
    {
        return $this->hasMany(BonusClaim::class);
    }

    public function memberBonuses(): HasMany
    {
        return $this->hasMany(MemberBonus::class);
    }

    public function shamoParticipations(): HasMany
    {
        return $this->hasMany(ShamoParticipation::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function withdrawalOrders(): HasMany
    {
        return $this->hasMany(AgentOrder::class)->where('type', 'withdrawal');
    }

    public function depositOrders(): HasMany
    {
        return $this->hasMany(AgentOrder::class)->where('type', 'deposit');
    }

    public function telegramSubscriber(): HasOne
    {
        return $this->hasOne(TelegramSubscriber::class);
    }

    /**
     * The account behind whatever a player types to sign in: a phone number in
     * any of its spellings (0912…, 251912…, +251 912…), a handle with or without
     * the @, or — for administrators — an email address. Sign-in and password
     * recovery must agree on this, so it lives here.
     */
    public static function findByLogin(string $login): ?self
    {
        $login = trim($login);
        if ($login === '' || mb_strlen($login) > 190) {
            return null;
        }

        $phones = array_values(array_unique(array_merge([$login], \App\Support\PhoneNumber::candidates($login))));

        return static::query()
            ->where(fn ($query) => $query
                ->whereIn('phone', $phones)
                ->orWhere('username', ltrim($login, '@'))
                ->orWhere('email', $login))
            ->orderBy('id')
            ->first();
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /** A player who gave an email address that has not been confirmed yet. */
    public function hasPendingEmailVerification(): bool
    {
        return $this->role === self::ROLE_PLAYER && filled($this->email) && $this->email_verified_at === null;
    }

    /**
     * Whether restricted features (withdrawals) are held until the email is
     * confirmed. Only while the operator's policy is "required" — see
     * App\Services\RegistrationGuard::emailMode() — so turning the policy off
     * releases every player at once.
     */
    public function needsEmailVerification(): bool
    {
        return $this->role === self::ROLE_PLAYER
            && $this->email_verified_at === null
            && \App\Services\RegistrationGuard::emailMode() === \App\Services\RegistrationGuard::EMAIL_REQUIRED;
    }

    public function isSuperAdmin(): bool
    {
        return $this->isAdmin() && ($this->admin_role === self::ADMIN_ROLE_SUPER || $this->admin_role === null);
    }

    public function hasAdminPermission(string $permission): bool
    {
        if (! $this->isAdmin() || ! $this->active) {
            return false;
        }

        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($permission, $this->admin_permissions ?: self::permissionsForRole($this->admin_role), true);
    }

    /** @return array<string, string> */
    public static function adminRoles(): array
    {
        return [
            self::ADMIN_ROLE_SUPER => 'Super administrator',
            self::ADMIN_ROLE_OPERATIONS => 'Operations',
            self::ADMIN_ROLE_FINANCE => 'Finance',
            self::ADMIN_ROLE_MARKETING => 'Marketing',
            self::ADMIN_ROLE_SUPPORT => 'Customer support',
        ];
    }

    /**
     * @return array<int, string>
     *
     * Note: `members.delete` is deliberately absent from every delegated role. Archiving a
     * member is a super-administrator action.
     */
    public static function permissionsForRole(?string $role): array
    {
        return match ($role) {
            self::ADMIN_ROLE_OPERATIONS => ['dashboard.view', 'operations.view', 'audit.view', 'reconciliation.view', 'members.manage', 'members.credentials', 'agents.manage', 'agents.finance', 'casino.manage', 'transactions.view'],
            self::ADMIN_ROLE_FINANCE => ['dashboard.view', 'operations.view', 'audit.view', 'reconciliation.view', 'members.deposit', 'payments.manage', 'transactions.view', 'agents.finance'],
            self::ADMIN_ROLE_MARKETING => ['dashboard.view', 'promotions.manage', 'telegram.manage'],
            self::ADMIN_ROLE_SUPPORT => ['dashboard.view', 'operations.view', 'members.manage', 'members.credentials', 'transactions.view'],
            self::ADMIN_ROLE_SUPER, null => ['*'],
            default => [],
        };
    }
}
