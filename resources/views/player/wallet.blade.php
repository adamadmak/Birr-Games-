@extends('layouts.app')

@section('title', __('My Wallet').' - Vamoseone')

@section('content')
@php
    /* One place decides whether an order can be rated, so the deposit and the
       withdrawal tables cannot drift apart on the rules. */
    $ratingCell = function ($order) {
        if ($order->rating) {
            return view('partials.agent-rating', ['order' => $order, 'existing' => $order->rating])->render();
        }
        if ($order->status === 'success' && $order->agent_id) {
            return view('partials.agent-rating', ['order' => $order, 'existing' => null])->render();
        }
        return '';
    };
@endphp
@php($lockedBalance = max(0, auth()->user()->credits - auth()->user()->withdrawable_balance))
<div class="player-account-page wallet-transaction-page">
    <header class="player-page-heading wallet-page-heading">
        <p>{{ __('Ethiopian Birr') }}</p>
        <h1>{{ __('Wallet') }}</h1>
        <span>{{ __('Manage your ETB balance and transactions.') }}</span>
    </header>

    <section class="wallet-balance-board wallet-balance-reference">
        <div class="wallet-total">
            <span class="wallet-board-icon">&#9670;</span>
            <div><small>{{ __('Total Balance') }}</small><strong><em>ETB</em> {{ \App\Support\Money::amount(auth()->user()->credits) }}</strong></div>
        </div>
        {{-- A balance is never rounded up: 23,000.75 shown as 23,001 is a figure the
             player cannot withdraw, and they have no way to tell it apart from a real one. --}}
        <div class="wallet-stat wallet-stat-green"><small>{{ __('Withdrawable') }}</small><strong>{{ \App\Support\Money::exact(auth()->user()->withdrawable_balance) }}</strong></div>
        <div class="wallet-stat wallet-stat-gold"><small>{{ __('Bonus / Play-only') }}</small><strong>{{ \App\Support\Money::exact($lockedBalance) }}</strong>@if($lockedBalance > 0)<span class="wallet-stat-note">{{ __('Play it, keep what it wins. It cannot be withdrawn.') }}@if($bonusInfo['outstanding'] > 0 && $bonusInfo['expires']) {{ __('Bonus ends :date.', ['date' => $bonusInfo['expires']->format('M j')]) }}@endif</span>@endif</div>
    </section>

    <nav class="wallet-mode-tabs" aria-label="{{ __('Wallet transaction type') }}">
        <a class="{{ $tab === 'deposit' ? 'active' : '' }}" href="{{ route('player.wallet', ['tab' => 'deposit']) }}"><span>&#8601;</span> {{ __('Deposit') }}</a>
        <a class="{{ $tab === 'withdraw' ? 'active' : '' }}" href="{{ route('player.wallet', ['tab' => 'withdraw']) }}"><span>&#8599;</span> {{ __('Withdraw') }}</a>
    </nav>

    @if($tab === 'withdraw')
        <section class="wd-section">
            <div class="wd-card">
                <header class="wd-card-header">
                    <div class="wd-card-title">
                        <span class="wd-card-icon">&#8599;</span>
                        <h2>{{ __('Request Withdraw') }}</h2>
                    </div>
                    <a href="{{ route('player.wallet', ['tab' => 'deposit']) }}" class="wd-close-btn" aria-label="{{ __('Close') }}">&times;</a>
                </header>
                <p class="wd-card-subtitle">{{ __('Withdraw your balance to your registered bank account.') }}</p>

                @if(!auth()->user()->active)
                    <div class="wallet-alert danger"><strong>{{ __('Wallet inactive') }}</strong><span>{{ __('An administrator must activate your wallet before you can withdraw.') }}</span></div>
                @elseif(! $withdrawalRange['open'])
                    <div class="wallet-alert"><strong>{{ __($withdrawalRange['heading']) }}</strong><span>{{ $withdrawalRange['note'] }}</span></div>
                @endif

                <form class="wd-form" method="POST" action="{{ route('player.withdrawals.store') }}">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $withdrawalIdempotencyKey) }}">

                    <div class="wd-form-section">
                        <label class="wd-section-label">{{ __('Enter Amount') }}</label>
                        <div class="wd-amount-display">
                            <span class="wd-currency">ETB</span>
                            {{-- Bounds only while there is a band to state. This used to clamp the
                                 ceiling up to the minimum whenever it fell below it, so min and max
                                 met and the browser answered every entry with "Value must be 30." --}}
                            <input type="number" name="amount" value="{{ old('amount') }}" @if($withdrawalRange['open']) min="{{ $withdrawalRange['minimum'] }}" max="{{ $withdrawalRange['maximum'] }}" @endif step="0.01" placeholder="0.00" class="wd-amount-input" required @disabled(! $withdrawalRange['open'])>
                        </div>
                        <div class="wd-amount-hints">
                            <span>{{ __('Available: :amount', ['amount' => \App\Support\Money::exact(auth()->user()->withdrawable_balance)]) }}</span>
                            {{-- The band that actually applies. "Max" was the platform setting, which
                                 is one of three limits and seldom the binding one. --}}
                            @if($withdrawalRange['open'])<span>{{ __('Request :min – :max', ['min' => \App\Support\Money::exact($withdrawalRange['minimum']), 'max' => \App\Support\Money::exact($withdrawalRange['maximum'])]) }}</span>@endif
                            @if(auth()->user()->withdrawal_cap !== null)<span>{{ __('Cap: :amount', ['amount' => \App\Support\Money::format(auth()->user()->withdrawal_cap)]) }}</span>@endif
                        </div>
                    </div>

                    @if($bonusInfo['forfeits'] && $bonusInfo['outstanding'] > 0)
                        <div class="wallet-alert">
                            <strong>{{ __('You still have a bonus') }}</strong>
                            <span>{{ __('Withdrawing cancels the :amount bonus you have not used yet. Your winnings and deposit are not affected.', ['amount' => \App\Support\Money::exact($bonusInfo['outstanding'])]) }}</span>
                            <label class="wallet-confirm"><input type="checkbox" name="acknowledge_bonus_forfeit" value="1" @checked(old('acknowledge_bonus_forfeit'))> <span>{{ __('I understand and want to withdraw') }}</span></label>
                        </div>
                    @endif

                    <div class="wd-form-section">
                        <label class="wd-section-label">{{ __('Select Bank') }}</label>
                        <div class="wd-bank-grid">
                            @forelse($gateways as $gateway)
                                <label class="wd-bank-option">
                                    <input type="radio" name="payment_gateway_id" value="{{ $gateway->id }}" @checked(old('payment_gateway_id', $gateways->first()?->id) == $gateway->id)>
                                    <span class="wd-bank-logo">
                                        @if($gateway->logo_url)
                                            <img src="{{ $gateway->logo_url }}" alt="{{ $gateway->name }}">
                                        @else
                                            {{ strtoupper(substr($gateway->name, 0, 1)) }}
                                        @endif
                                    </span>
                                    <span class="wd-bank-info">
                                        <strong>{{ $gateway->name }}</strong>
                                        <small class="unit">{{ $gateway->account_label ?: __('Payout account') }}</small>
                                    </span>
                                    <i class="wd-bank-check">&#10003;</i>
                                </label>
                            @empty
                                <div class="wallet-alert danger"><strong>{{ __('No payout methods available') }}</strong><span>{{ __('Ask an administrator to enable a withdrawal gateway.') }}</span></div>
                            @endforelse
                        </div>
                    </div>

                    <div class="wd-form-section">
                        <label class="wd-section-label">{{ __('Account Name') }}</label>
                        <div class="wd-input-wrap">
                            <span class="wd-input-icon">&#9673;</span>
                            <input name="account_name" value="{{ old('account_name', auth()->user()->name) }}" maxlength="120" autocomplete="name" placeholder="{{ __('e.g. John Doe') }}" required>
                        </div>
                    </div>

                    <div class="wd-form-section">
                        <label class="wd-section-label">{{ __('Account Number') }}</label>
                        <div class="wd-input-wrap">
                            <span class="wd-input-icon">&#9635;</span>
                            <input name="account_number" value="{{ old('account_number', auth()->user()->phone) }}" maxlength="80" inputmode="numeric" placeholder="{{ __('Enter account or phone number') }}" required>
                        </div>
                    </div>

                    <div class="wd-info-note">
                        <span class="wd-info-icon">&#9432;</span>
                        <p>{{ __('Administrator review required. The amount is reserved immediately and restored automatically if rejected or cancelled.') }}</p>
                    </div>

                    <div class="wd-actions">
                        <a href="{{ route('player.wallet') }}" class="wd-cancel-btn">{{ __('Cancel') }}</a>
                        <button type="submit" class="wd-submit-btn" @disabled(!auth()->user()->active || ! $withdrawalRange['open'] || $gateways->isEmpty())>{{ __('Confirm Payout') }} <span>&rarr;</span></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="wd-history">
            <header class="wd-history-header">
                <div>
                    <h2>{{ __('Withdraw Order History') }}</h2>
                    <p>{{ __('Recent withdraw transactions') }}</p>
                </div>
                <div class="wd-history-badges">
                    <span class="wd-pending-badge">{{ __(':count pending', ['count' => $pendingWithdrawals]) }}</span>
                </div>
            </header>
            <div class="wallet-history-table">
                <div class="wallet-history-row withdrawal-history-row wallet-history-head"><span>{{ __('Reference') }}</span><span>{{ __('Method') }}</span><span>{{ __('Account') }}</span><span>{{ __('Amount') }}</span><span>{{ __('Status') }}</span><span>{{ __('Requested') }}</span><span>{{ __('Action') }}</span></div>
                @forelse($withdrawalOrders as $order)
                    <div class="wallet-history-row withdrawal-history-row">
                        <strong>{{ $order->reference }}</strong>
                        <span>
                            @if($order->gateway?->logo_url)
                                <img src="{{ $order->gateway->logo_url }}" alt="{{ $order->gateway->name }}" class="history-gateway-logo">
                            @endif
                            {{ $order->gateway?->name ?: '-' }}
                        </span>
                        <span>{{ $order->account_number ? str($order->account_number)->mask('*', 0, max(0, strlen($order->account_number) - 4)) : '-' }}</span>
                        <em class="negative">-{{ \App\Support\Money::format($order->amount) }}</em>
                        <span class="withdrawal-status {{ $order->status }}">{{ __(ucfirst($order->status)) }}</span>
                        <small>{{ $order->created_at->diffForHumans() }}</small>
                        <span>@if($order->status === 'pending')<form method="POST" action="{{ route('player.withdrawals.cancel', $order) }}">@csrf @method('PATCH')<button class="withdrawal-cancel-button" type="submit">{{ __('Cancel') }}</button></form>@else {{ $order->processed_at?->format('M j') ?: '-' }} @endif</span>
                    </div>
                @empty
                    <div class="wd-empty-state">
                        <span class="wd-empty-icon">&#9203;</span>
                        <h3>{{ __('No recent orders found') }}</h3>
                    </div>
                @endforelse
            </div>
            @if($withdrawalOrders->hasPages())<div class="wallet-pagination">@if($withdrawalOrders->onFirstPage())<span>{{ __('Previous') }}</span>@else<a href="{{ $withdrawalOrders->previousPageUrl() }}">{{ __('Previous') }}</a>@endif<strong>{{ __('Page :current of :last', ['current' => $withdrawalOrders->currentPage(), 'last' => $withdrawalOrders->lastPage()]) }}</strong>@if($withdrawalOrders->hasMorePages())<a href="{{ $withdrawalOrders->nextPageUrl() }}">{{ __('Next') }}</a>@else<span>{{ __('Next') }}</span>@endif</div>@endif
        </section>
    @else
        @if($bonusInfo['offer'])
            @php($offer = $bonusInfo['offer'])
            <aside class="deposit-bonus-offer" data-deposit-bonus-offer data-percent="{{ $offer['percent'] }}" data-max="{{ $offer['max_amount'] }}" data-min="{{ $offer['min_deposit'] }}">
                <span class="deposit-bonus-badge">+{{ rtrim(rtrim(number_format($offer['percent'], 2), '0'), '.') }}%</span>
                <div>
                    <strong>{{ $offer['scope'] === 'first' ? __('First deposit bonus') : __('Deposit bonus') }}</strong>
                    <p>{{ __('Deposit :amount or more and get :percent% extra to play with', ['amount' => \App\Support\Money::format($offer['min_deposit']), 'percent' => rtrim(rtrim(number_format($offer['percent'], 2), '0'), '.')]) }}@if($offer['max_amount'] > 0){{ __(', up to :amount', ['amount' => \App\Support\Money::format($offer['max_amount'])]) }}@endif. {{ __('The bonus is play-only: whatever it wins is yours to withdraw, but the bonus itself cannot be withdrawn.') }}@if($offer['valid_days'] > 0) {{ __('It lasts :days days.', ['days' => $offer['valid_days']]) }}@endif</p>
                </div>
            </aside>
        @endif
        <section class="deposit-payment-section">
            <div class="wallet-section-title">
                <div><p>{{ __('Fund your Birr wallet') }}</p><h2>{{ __('Select Payment Method') }}</h2></div>
                <span>{{ __(':count available', ['count' => $depositGateways->count()]) }}</span>
            </div>

            @if($depositGateways->isEmpty())
                <div class="wallet-alert danger"><strong>{{ __('No deposit methods available') }}</strong><span>{{ __('An agent must add and activate a bank account before deposits can be submitted.') }}</span></div>
            @else
                <div class="deposit-method-grid">
                    @foreach($depositGateways as $gateway)
                        <button type="button" class="deposit-method-card" data-deposit-method data-gateway-id="{{ $gateway->id }}" data-gateway-name="{{ $gateway->name }}">
                            <span class="deposit-method-icon">
                                @if($gateway->logo_url)
                                    <img src="{{ $gateway->logo_url }}" alt="{{ $gateway->name }}">
                                @else
                                    &#9635;
                                @endif
                            </span>
                            <strong>{{ $gateway->name }}</strong>
                            <small>{{ trans_choice('{1}:count agent account|[2,*]:count agent accounts', $depositAccounts->where('payment_gateway_id', $gateway->id)->count(), ['count' => $depositAccounts->where('payment_gateway_id', $gateway->id)->count()]) }}</small>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="wallet-history deposit-order-history">
            <div class="wallet-section-title"><div><p>{{ __('Recent deposit transactions') }}</p><h2>{{ __('Deposit Order History') }}</h2></div><span>{{ __(':count pending', ['count' => $pendingDeposits]) }}</span></div>
            <div class="wallet-history-table">
                <div class="wallet-history-row deposit-history-row wallet-history-head"><span>{{ __('Reference') }}</span><span>{{ __('Agent') }}</span><span>{{ __('Bank account') }}</span><span>{{ __('Amount') }}</span><span>{{ __('Status') }}</span><span>{{ __('Requested') }}</span><span>{{ __('Action') }}</span></div>
                @forelse($depositOrders as $order)
                    <div class="wallet-history-row deposit-history-row">
                        <strong>{{ $order->reference ?: 'ORDER-'.$order->id }}</strong>
                        <span>{{ $order->agent?->nickname ?: __('Assigned agent') }}</span>
                        <span>
                            @if($order->gateway?->logo_url)
                                <img src="{{ $order->gateway->logo_url }}" alt="{{ $order->gateway->name }}" class="history-gateway-logo">
                            @endif
                            {{ $order->gateway?->name ?: __('Agent bank') }}<small>{{ $order->account_number }}</small>
                        </span>
                        <em class="positive">+{{ \App\Support\Money::format($order->amount) }}</em>
                        <span class="withdrawal-status {{ $order->status }}">{{ __(ucfirst($order->status)) }}</span>
                        <small>{{ $order->created_at->diffForHumans() }}</small>
                        <span>{!! $ratingCell($order) !!}@if($order->status === 'pending')<form method="POST" action="{{ route('player.deposits.cancel', $order) }}">@csrf @method('PATCH')<button class="withdrawal-cancel-button" type="submit">{{ __('Cancel') }}</button></form>@else {{ $order->processed_at?->format('M j') ?: '-' }} @endif</span>
                    </div>
                @empty
                    <div class="wallet-empty"><span>&#9671;</span><h3>{{ __('No deposit orders found') }}</h3><p>{{ __('Choose a payment method above to submit your first agent-reviewed deposit.') }}</p></div>
                @endforelse
            </div>
            @if($depositOrders->hasPages())<div class="wallet-pagination">{{ $depositOrders->links() }}</div>@endif
        </section>

        <dialog class="deposit-flow-dialog" data-deposit-dialog>
            <form method="POST" action="{{ route('player.deposits.store') }}" data-deposit-form>
                @csrf
                <input type="hidden" name="agent_bank_account_id" data-deposit-account-id>
                <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $depositIdempotencyKey) }}">

                <section class="deposit-flow-step active" data-deposit-step="amount">
                    <header><div><small>{{ __('Secure ETB deposit') }}</small><h2>{{ __('Deposit Amount') }}</h2><p>{{ __('Payment Method:') }} <strong data-deposit-method-name></strong></p></div><button type="button" data-deposit-close aria-label="{{ __('Close') }}">&times;</button></header>
                    {{-- Zero is "no maximum" to FinancialRuleService, so it must not be written
                         into the field, where it would instead block every deposit. --}}
                    <label>{{ __('Amount') }}<input type="number" name="amount" min="{{ $minimumDeposit }}" @if($maximumDeposit > 0) max="{{ $maximumDeposit }}" @endif step="0.01" placeholder="{{ __('Enter your amount') }}" required data-deposit-amount></label>
                    @if($bonusInfo['offer'])<p class="deposit-bonus-preview" data-deposit-bonus-preview hidden></p>@endif
                    <button class="deposit-flow-primary" type="button" data-deposit-next-agent>{{ __('Continue') }}</button>
                    <p class="deposit-flow-hint">{{ __('Minimum :amount', ['amount' => \App\Support\Money::format($minimumDeposit)]) }} · {{ $maximumDeposit > 0 ? __('Maximum :amount', ['amount' => \App\Support\Money::format($maximumDeposit)]) : __('No maximum') }}</p>
                </section>

                <section class="deposit-flow-step" data-deposit-step="agent">
                    <header><div><small>{{ __('Amount') }} <strong data-deposit-amount-label></strong> · {{ __('Method') }} <strong data-deposit-method-label></strong></small><h2>{{ __('Choose Agent') }}</h2><p>{{ __('Select the agent account that will receive and verify your payment.') }}</p></div><button type="button" data-deposit-close aria-label="{{ __('Close') }}">&times;</button></header>
                    <div class="deposit-agent-list">
                        @foreach($depositAccounts as $account)
                            <button type="button" class="deposit-agent-card" data-deposit-agent data-gateway-id="{{ $account->payment_gateway_id }}" data-account-id="{{ $account->id }}" data-agent-name="{{ $account->agent->nickname }}" data-bank-name="{{ $account->gateway->name }}" data-account-name="{{ $account->account_name }}" data-account-number="{{ $account->account_number }}">
                                <span class="deposit-agent-avatar">
                                    @if($account->gateway?->logo_url)
                                        <img src="{{ $account->gateway->logo_url }}" alt="{{ $account->gateway->name }}">
                                    @else
                                        {{ strtoupper(substr($account->agent->nickname, 0, 1)) }}
                                    @endif
                                </span>
                                <span>
                                    <strong>{{ $account->agent->nickname }}</strong>
                                    <small>&#9742; {{ $account->account_number }}</small>
                                    {{-- Presence and service record, at the moment the choice is made:
                                         an offline agent means a deposit that waits. --}}
                                    <span class="deposit-agent-meta">
                                        @if(isset($onlineAgentIds[$account->agent_id]))
                                            <em class="agent-presence is-online"><i></i>{{ __('Online now') }}</em>
                                        @else
                                            <em class="agent-presence is-offline"><i></i>{{ __('Offline') }}</em>
                                        @endif
                                        @if($account->agent->ratings_count > 0)
                                            <em class="agent-score" title="{{ trans_choice('{1}:count rating|[2,*]:count ratings', $account->agent->ratings_count, ['count' => number_format($account->agent->ratings_count)]) }}">
                                                &#9733; {{ number_format($account->agent->ratings_avg_score, 1) }}
                                            </em>
                                        @endif
                                    </span>
                                </span>
                                <i>&#10003;</i>
                            </button>
                        @endforeach
                    </div>
                    <footer><button type="button" class="deposit-flow-secondary" data-deposit-back-amount>{{ __('Back') }}</button><button type="button" class="deposit-flow-primary" data-deposit-next-reference disabled>{{ __('Confirm Deposit') }} &#10003;</button></footer>
                </section>

                <section class="deposit-flow-step" data-deposit-step="reference">
                    <header><div><small>{{ __('Final step') }}</small><h2>{{ __('Send Payment') }}</h2><p>{{ __('Transfer the exact amount, then enter the bank reference.') }}</p></div><button type="button" data-deposit-close aria-label="{{ __('Close') }}">&times;</button></header>
                    <div class="deposit-instructions">
                        <span>{{ __('Amount') }}<strong data-confirm-amount></strong></span>
                        <span>{{ __('Bank') }}<strong data-confirm-bank></strong></span>
                        <span>{{ __('Account Number') }}<strong data-confirm-number></strong></span>
                        <span>{{ __('Account Name') }}<strong data-confirm-name></strong></span>
                        <span>{{ __('Agent') }}<strong data-confirm-agent></strong></span>
                    </div>
                    <label>{{ __('Bank reference or confirmation message') }}<input name="reference" minlength="3" maxlength="100" placeholder="{{ __('Enter payment reference') }}" required></label>
                    <footer><button type="button" class="deposit-flow-secondary" data-deposit-back-agent>{{ __('Back') }}</button><button type="submit" class="deposit-flow-primary">{{ __('Submit For Approval') }}</button></footer>
                    <p class="deposit-flow-hint">{{ __('Your balance changes only after the assigned agent verifies the payment.') }}</p>
                </section>
            </form>
        </dialog>
    @endif
</div>
@if($bonusInfo['offer'])
    <script src="/js/wallet-bonus.js?v={{ @filemtime(public_path('js/wallet-bonus.js')) ?: time() }}" defer></script>
@endif
@endsection
