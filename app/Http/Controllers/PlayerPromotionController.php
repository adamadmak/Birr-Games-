<?php

namespace App\Http\Controllers;

use App\Models\BonusClaim;
use App\Models\PlatformSetting;
use App\Models\ShamoCampaign;
use App\Models\ShamoParticipation;
use App\Models\User;
use App\Services\Wallet\InsufficientFunds;
use App\Services\Wallet\LedgerEntry;
use App\Services\Wallet\PlayerWallet;
use App\Support\Money;
use App\Services\WageringService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlayerPromotionController
{
    public function depositBonus(Request $request): View
    {
        return view('player.deposit-bonus', [
            'claim' => $request->user()->bonusClaims()
                ->where('bonus_type', BonusClaim::FIRST_DEMO_TOP_UP)
                ->first(),
            'bonusCredits' => $this->depositBonusAmount(),
            'hasDeposit' => $this->hasApprovedDeposit($request->user()),
        ]);
    }

    /** One approved (agent-settled) deposit is the price of the bonus. */
    private function hasApprovedDeposit(User $user): bool
    {
        return $user->depositOrders()->where('status', 'success')->exists();
    }

    public function claimDepositBonus(Request $request, WageringService $wagering): RedirectResponse
    {
        $bonusAmount = $this->depositBonusAmount();

        if ($bonusAmount < 1) {
            return back()->with('error', 'The deposit bonus is not available right now.');
        }

        $claimed = DB::transaction(function () use ($request, $bonusAmount, $wagering) {
            /** @var User $user */
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            if ($user->bonusClaims()->where('bonus_type', BonusClaim::FIRST_DEMO_TOP_UP)->exists()) {
                return 'already';
            }

            // Until 28 Oct 2026 this was a free 2,500 ETB for every account, and with an
            // even-odds coin flip in the lobby it was withdrawable within minutes. It is
            // a deposit bonus: no settled deposit, no bonus.
            if (! $this->hasApprovedDeposit($user)) {
                return 'no_deposit';
            }

            $user->increment('credits', $bonusAmount);
            $claim = $user->bonusClaims()->create([
                'bonus_type' => BonusClaim::FIRST_DEMO_TOP_UP,
                'credits' => $bonusAmount,
                'claimed_at' => now(),
            ]);
            WalletTransaction::create([
                'user_id' => $user->id, 'type' => 'bonus', 'amount' => $bonusAmount,
                'status' => 'success', 'method' => 'promotion', 'reason' => 'Deposit bonus',
                'currency' => 'ETB', 'source_type' => 'bonus_claim', 'source_id' => $claim->id,
            ]);
            $wagering->awardForClaim($user, $claim);

            return 'claimed';
        });

        return match ($claimed) {
            'claimed' => back()->with('status', Money::format($bonusAmount).' was added to your wallet.'),
            'no_deposit' => back()->with('error', 'Make your first deposit, then come back for the bonus.'),
            default => back()->with('status', 'This one-time bonus has already been claimed.'),
        };
    }

    public function shamo(Request $request): View
    {
        $userId = $request->user()->id;

        return view('player.shamo', [
            'campaigns' => ShamoCampaign::query()
                ->where('status', ShamoCampaign::STATUS_ACTIVE)
                ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', now()))
                ->with(['participations' => fn ($query) => $query->where('user_id', $userId)])
                ->withCount('participations')
                ->orderBy('ends_at')
                ->get(),
            'history' => $request->user()->shamoParticipations()->with('campaign')->latest('joined_at')->get(),
        ]);
    }

    public function joinShamo(Request $request, ShamoCampaign $campaign, PlayerWallet $wallet): RedirectResponse
    {
        $result = DB::transaction(function () use ($request, $campaign, $wallet) {
            $lockedCampaign = ShamoCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $user = $wallet->lock($request->user());

            if (!$lockedCampaign->isOpen()) {
                return 'closed';
            }

            if (ShamoParticipation::query()->where('shamo_campaign_id', $lockedCampaign->id)->where('user_id', $user->id)->exists()) {
                return 'joined';
            }

            // The entry fee is a stake: bonus credit first, cents, a ledger row.
            // It is not play, so it does not count toward a bonus rollover.
            try {
                $wallet->stake($user, (float) $lockedCampaign->entry_cost, 'shamo', '', new LedgerEntry(
                    method: 'shamo',
                    reason: $lockedCampaign->name.' entry',
                    sourceType: 'shamo_campaign',
                    sourceId: $lockedCampaign->id,
                    type: 'campaign_entry',
                ), wagers: false);
            } catch (InsufficientFunds) {
                return 'balance';
            }

            ShamoParticipation::create([
                'shamo_campaign_id' => $lockedCampaign->id,
                'user_id' => $user->id,
                'status' => ShamoParticipation::STATUS_JOINED,
                'joined_at' => now(),
            ]);

            return 'success';
        });

        $message = match ($result) {
            'success' => 'You joined '.$campaign->name.'. Your participation is now active.',
            'joined' => 'You already joined this Shamo campaign.',
            'balance' => 'Your Birr balance is too low for this campaign.',
            default => 'This campaign is no longer accepting participants.',
        };

        return back()->with($result === 'success' ? 'status' : 'error', $message);
    }

    public function claimShamo(Request $request, ShamoParticipation $participation, PlayerWallet $wallet): RedirectResponse
    {
        abort_unless($participation->user_id === $request->user()->id, 403);

        $claimed = DB::transaction(function () use ($request, $participation, $wallet) {
            $locked = ShamoParticipation::query()->lockForUpdate()->findOrFail($participation->id);
            $user = $wallet->lock($request->user());

            if ($locked->status !== ShamoParticipation::STATUS_AWARDED || $locked->prize_amount < 1) {
                return false;
            }

            // A prize is real money, all of it (PlayerWallet::reward).
            $wallet->reward($user, (float) $locked->prize_amount, new LedgerEntry(
                method: 'shamo',
                reason: 'Shamo campaign prize',
                sourceType: 'shamo_participation',
                sourceId: $locked->id,
                type: 'campaign_prize',
            ));
            $locked->update(['status' => ShamoParticipation::STATUS_CLAIMED, 'claimed_at' => now()]);

            return true;
        });

        return back()->with($claimed ? 'status' : 'error', $claimed
            ? 'Your Shamo prize was added to your Birr wallet.'
            : 'This reward is not available to claim.');
    }

    private function depositBonusAmount(): int
    {
        return max(0, (int) PlatformSetting::valueFor('deposit_bonus_amount', 2_500));
    }
}
