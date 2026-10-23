@extends('layouts.app')

@section('title', 'My Wallet - Birr Games')

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
        <p>Ethiopian Birr</p>
        <h1>Wallet</h1>
        <span>Manage your ETB balance and transactions.</span>
    </header>

    <section class="wallet-balance-board wallet-balance-reference">
        <div class="wallet-total">
            <span class="wallet-board-icon">&#9670;</span>
            <div><small>Total Balance</small><strong><em>ETB</em> {{ \App\Support\Money::amount(auth()->user()->credits) }}</strong></div>
        </div>
        {{-- A balance is never rounded up: 23,000.75 shown as 23,001 is a figure the
             player cannot withdraw, and they have no way to tell it apart from a real one. --}}
        <div class="wallet-stat wallet-stat-green"><small>Withdrawable</small><strong>{{ \App\Support\Money::exact(auth()->user()->withdrawable_balance) }}</strong></div>
        <div class="wallet-stat wallet-stat-gold"><small>Bonus / Play-only</small><strong>{{ \App\Support\Money::exact($lockedBalance) }}</strong>@if($lockedBalance > 0)<span class="wallet-stat-note">Play it, keep what it wins. It cannot be withdrawn.@if($bonusInfo['outstanding'] > 0 && $bonusInfo['expires']) Bonus ends {{ $bonusInfo['expires']->format('M j') }}.@endif</span>@endif</div>
    </section>

    <nav class="wallet-mode-tabs" aria-label="Wallet transaction type">
        <a class="{{ $tab === 'deposit' ? 'active' : '' }}" href="{{ route('player.wallet', ['tab' => 'deposit']) }}"><span>&#8601;</span> Deposit</a>
        <a class="{{ $tab === 'withdraw' ? 'active' : '' }}" href="{{ route('player.wallet', ['tab' => 'withdraw']) }}"><span>&#8599;</span> Withdraw</a>
    </nav>

    @if($tab === 'withdraw')
        <section class="wd-section">
            <div class="wd-card">
                <header class="wd-card-header">
                    <div class="wd-card-title">
                        <span class="wd-card-icon">&#8599;</span>
                        <h2>Request Withdraw</h2>
                    </div>
                    <a href="{{ route('player.wallet', ['tab' => 'deposit']) }}" class="wd-close-btn" aria-label="Close">&times;</a>
                </header>
                <p class="wd-card-subtitle">Withdraw your balance to your registered bank account.</p>

                @if(!auth()->user()->active)
                    <div class="wallet-alert danger"><strong>Wallet inactive</strong><span>An administrator must activate your wallet before you can withdraw.</span></div>
                @elseif(! $withdrawalRange['open'])
                    <div class="wallet-alert"><strong>{{ $withdrawalRange['heading'] }}</strong><span>{{ $withdrawalRange['note'] }}</span></div>
                @endif

                <form class="wd-form" method="POST" action="{{ route('player.withdrawals.store') }}">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $withdrawalIdempotencyKey) }}">

                    <div class="wd-form-section">
                        <label class="wd-section-label">Enter Amount</label>
                        <div class="wd-amount-display">
                            <span class="wd-currency">ETB</span>
                            {{-- Bounds only while there is a band to state. This used to clamp the
                                 ceiling up to the minimum whenever it fell below it, so min and max
                                 met and the browser answered every entry with "Value must be 30." --}}
                            <input type="number" name="amount" value="{{ old('amount') }}" @if($withdrawalRange['open']) min="{{ $withdrawalRange['minimum'] }}" max="{{ $withdrawalRange['maximum'] }}" @endif step="0.01" placeholder="0.00" class="wd-amount-input" required @disabled(! $withdrawalRange['open'])>
                        </div>
                        <div class="wd-amount-hints">
                            <span>Available: {{ \App\Support\Money::exact(auth()->user()->withdrawable_balance) }}</span>
                            {{-- The band that actually applies. "Max" was the platform setting, which
                                 is one of three limits and seldom the binding one. --}}
                            @if($withdrawalRange['open'])<span>Request {{ \App\Support\Money::exact($withdrawalRange['minimum']) }} &ndash; {{ \App\Support\Money::exact($withdrawalRange['maximum']) }}</span>@endif
                            @if(auth()->user()->withdrawal_cap !== null)<span>Cap: {{ \App\Support\Money::format(auth()->user()->withdrawal_cap) }}</span>@endif
                        </div>
                    </div>

                    @if($bonusInfo['forfeits'] && $bonusInfo['outstanding'] > 0)
                        <div class="wallet-alert">
                            <strong>You still have a bonus</strong>
                            <span>Withdrawing cancels the {{ \App\Support\Money::exact($bonusInfo['outstanding']) }} bonus you have not used yet. Your winnings and deposit are not affected.</span>
                            <label class="wallet-confirm"><input type="checkbox" name="acknowledge_bonus_forfeit" value="1" @checked(old('acknowledge_bonus_forfeit'))> <span>I understand and want to withdraw</span></label>
                        </div>
                    @endif

                    <div class="wd-form-section">
                        <label class="wd-section-label">Select Bank</label>
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
                                        <small class="unit">{{ $gateway->account_label ?: 'Payout account' }}</small>
                                    </span>
                                    <i class="wd-bank-check">&#10003;</i>
                                </label>
                            @empty
                                <div class="wallet-alert danger"><strong>No payout methods available</strong><span>Ask an administrator to enable a withdrawal gateway.</span></div>
                            @endforelse
                        </div>
                    </div>

                    <div class="wd-form-section">
                        <label class="wd-section-label">Account Name</label>
                        <div class="wd-input-wrap">
                            <span class="wd-input-icon">&#9673;</span>
                            <input name="account_name" value="{{ old('account_name', auth()->user()->name) }}" maxlength="120" autocomplete="name" placeholder="e.g. John Doe" required>
                        </div>
                    </div>

                    <div class="wd-form-section">
                        <label class="wd-section-label">Account Number</label>
                        <div class="wd-input-wrap">
                            <span class="wd-input-icon">&#9635;</span>
                            <input name="account_number" value="{{ old('account_number', auth()->user()->phone) }}" maxlength="80" inputmode="numeric" placeholder="Enter account or phone number" required>
                        </div>
                    </div>

                    <div class="wd-info-note">
                        <span class="wd-info-icon">&#9432;</span>
                        <p>Administrator review required. The amount is reserved immediately and restored automatically if rejected or cancelled.</p>
                    </div>

                    <div class="wd-actions">
                        <a href="{{ route('player.wallet') }}" class="wd-cancel-btn">Cancel</a>
                        <button type="submit" class="wd-submit-btn" @disabled(!auth()->user()->active || ! $withdrawalRange['open'] || $gateways->isEmpty())>Confirm Payout <span>&rarr;</span></button>
                    </div>
                </form>
            </div>
        </section>

        <section class="wd-history">
            <header class="wd-history-header">
                <div>
                    <h2>Withdraw Order History</h2>
                    <p>Recent withdraw transactions</p>
                </div>
                <div class="wd-history-badges">
                    <span class="wd-pending-badge">{{ $pendingWithdrawals }} pending</span>
                </div>
            </header>
            <div class="wallet-history-table">
                <div class="wallet-history-row withdrawal-history-row wallet-history-head"><span>Reference</span><span>Method</span><span>Account</span><span>Amount</span><span>Status</span><span>Requested</span><span>Action</span></div>
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
                        <span class="withdrawal-status {{ $order->status }}">{{ ucfirst($order->status) }}</span>
                        <small>{{ $order->created_at->diffForHumans() }}</small>
                        <span>@if($order->status === 'pending')<form method="POST" action="{{ route('player.withdrawals.cancel', $order) }}">@csrf @method('PATCH')<button class="withdrawal-cancel-button" type="submit">Cancel</button></form>@else {{ $order->processed_at?->format('M j') ?: '-' }} @endif</span>
                    </div>
                @empty
                    <div class="wd-empty-state">
                        <span class="wd-empty-icon">&#9203;</span>
                        <h3>No recent orders found</h3>
                    </div>
                @endforelse
            </div>
            @if($withdrawalOrders->hasPages())<div class="wallet-pagination">@if($withdrawalOrders->onFirstPage())<span>Previous</span>@else<a href="{{ $withdrawalOrders->previousPageUrl() }}">Previous</a>@endif<strong>Page {{ $withdrawalOrders->currentPage() }} of {{ $withdrawalOrders->lastPage() }}</strong>@if($withdrawalOrders->hasMorePages())<a href="{{ $withdrawalOrders->nextPageUrl() }}">Next</a>@else<span>Next</span>@endif</div>@endif
        </section>
    @else
        @if($bonusInfo['offer'])
            @php($offer = $bonusInfo['offer'])
            <aside class="deposit-bonus-offer" data-deposit-bonus-offer data-percent="{{ $offer['percent'] }}" data-max="{{ $offer['max_amount'] }}" data-min="{{ $offer['min_deposit'] }}">
                <span class="deposit-bonus-badge">+{{ rtrim(rtrim(number_format($offer['percent'], 2), '0'), '.') }}%</span>
                <div>
                    <strong>{{ $offer['scope'] === 'first' ? 'First deposit bonus' : 'Deposit bonus' }}</strong>
                    <p>Deposit {{ \App\Support\Money::format($offer['min_deposit']) }} or more and get {{ rtrim(rtrim(number_format($offer['percent'], 2), '0'), '.') }}% extra to play with @if($offer['max_amount'] > 0), up to {{ \App\Support\Money::format($offer['max_amount']) }}@endif. The bonus is play-only: whatever it wins is yours to withdraw, but the bonus itself cannot be withdrawn.@if($offer['valid_days'] > 0) It lasts {{ $offer['valid_days'] }} days.@endif</p>
                </div>
            </aside>
        @endif
        <section class="deposit-payment-section">
            <div class="wallet-section-title">
                <div><p>Fund your Birr wallet</p><h2>Select Payment Method</h2></div>
                <span>{{ $depositGateways->count() }} available</span>
            </div>

            @if($depositGateways->isEmpty())
                <div class="wallet-alert danger"><strong>No deposit methods available</strong><span>An agent must add and activate a bank account before deposits can be submitted.</span></div>
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
                            <small>{{ $depositAccounts->where('payment_gateway_id', $gateway->id)->count() }} agent {{ \Illuminate\Support\Str::plural('account', $depositAccounts->where('payment_gateway_id', $gateway->id)->count()) }}</small>
                        </button>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="wallet-history deposit-order-history">
            <div class="wallet-section-title"><div><p>Recent deposit transactions</p><h2>Deposit Order History</h2></div><span>{{ $pendingDeposits }} pending</span></div>
            <div class="wallet-history-table">
                <div class="wallet-history-row deposit-history-row wallet-history-head"><span>Reference</span><span>Agent</span><span>Bank account</span><span>Amount</span><span>Status</span><span>Requested</span><span>Action</span></div>
                @forelse($depositOrders as $order)
                    <div class="wallet-history-row deposit-history-row">
                        <strong>{{ $order->reference ?: 'ORDER-'.$order->id }}</strong>
                        <span>{{ $order->agent?->nickname ?: 'Assigned agent' }}</span>
                        <span>
                            @if($order->gateway?->logo_url)
                                <img src="{{ $order->gateway->logo_url }}" alt="{{ $order->gateway->name }}" class="history-gateway-logo">
                            @endif
                            {{ $order->gateway?->name ?: 'Agent bank' }}<small>{{ $order->account_number }}</small>
                        </span>
                        <em class="positive">+{{ \App\Support\Money::format($order->amount) }}</em>
                        <span class="withdrawal-status {{ $order->status }}">{{ ucfirst($order->status) }}</span>
                        <small>{{ $order->created_at->diffForHumans() }}</small>
                        <span>{!! $ratingCell($order) !!}@if($order->status === 'pending')<form method="POST" action="{{ route('player.deposits.cancel', $order) }}">@csrf @method('PATCH')<button class="withdrawal-cancel-button" type="submit">Cancel</button></form>@else {{ $order->processed_at?->format('M j') ?: '-' }} @endif</span>
                    </div>
                @empty
                    <div class="wallet-empty"><span>&#9671;</span><h3>No deposit orders found</h3><p>Choose a payment method above to submit your first agent-reviewed deposit.</p></div>
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
                    <header><div><small>Secure ETB deposit</small><h2>Deposit Amount</h2><p>Payment Method: <strong data-deposit-method-name></strong></p></div><button type="button" data-deposit-close aria-label="Close">&times;</button></header>
                    {{-- Zero is "no maximum" to FinancialRuleService, so it must not be written
                         into the field, where it would instead block every deposit. --}}
                    <label>Amount<input type="number" name="amount" min="{{ $minimumDeposit }}" @if($maximumDeposit > 0) max="{{ $maximumDeposit }}" @endif step="0.01" placeholder="Enter your amount" required data-deposit-amount></label>
                    @if($bonusInfo['offer'])<p class="deposit-bonus-preview" data-deposit-bonus-preview hidden></p>@endif
                    <button class="deposit-flow-primary" type="button" data-deposit-next-agent>Continue</button>
                    <p class="deposit-flow-hint">Minimum {{ \App\Support\Money::format($minimumDeposit) }} · {{ $maximumDeposit > 0 ? 'Maximum '.\App\Support\Money::format($maximumDeposit) : 'No maximum' }}</p>
                </section>

                <section class="deposit-flow-step" data-deposit-step="agent">
                    <header><div><small>Amount <strong data-deposit-amount-label></strong> · Method <strong data-deposit-method-label></strong></small><h2>Choose Agent</h2><p>Select the agent account that will receive and verify your payment.</p></div><button type="button" data-deposit-close aria-label="Close">&times;</button></header>
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
                                            <em class="agent-score" title="{{ number_format($account->agent->ratings_count) }} {{ \Illuminate\Support\Str::plural('rating', $account->agent->ratings_count) }}">
                                                &#9733; {{ number_format($account->agent->ratings_avg_score, 1) }}
                                            </em>
                                        @endif
                                    </span>
                                </span>
                                <i>&#10003;</i>
                            </button>
                        @endforeach
                    </div>
                    <footer><button type="button" class="deposit-flow-secondary" data-deposit-back-amount>Back</button><button type="button" class="deposit-flow-primary" data-deposit-next-reference disabled>Confirm Deposit &#10003;</button></footer>
                </section>

                <section class="deposit-flow-step" data-deposit-step="reference">
                    <header><div><small>Final step</small><h2>Send Payment</h2><p>Transfer the exact amount, then enter the bank reference.</p></div><button type="button" data-deposit-close aria-label="Close">&times;</button></header>
                    <div class="deposit-instructions">
                        <span>Amount<strong data-confirm-amount></strong></span>
                        <span>Bank<strong data-confirm-bank></strong></span>
                        <span>Account Number<strong data-confirm-number></strong></span>
                        <span>Account Name<strong data-confirm-name></strong></span>
                        <span>Agent<strong data-confirm-agent></strong></span>
                    </div>
                    <label>Bank reference or confirmation message<input name="reference" minlength="3" maxlength="100" placeholder="Enter payment reference" required></label>
                    <footer><button type="button" class="deposit-flow-secondary" data-deposit-back-agent>Back</button><button type="submit" class="deposit-flow-primary">Submit For Approval</button></footer>
                    <p class="deposit-flow-hint">Your balance changes only after the assigned agent verifies the payment.</p>
                </section>
            </form>
        </dialog>
    @endif
</div>
@if($bonusInfo['offer'])
    <script src="/js/wallet-bonus.js?v={{ @filemtime(public_path('js/wallet-bonus.js')) ?: time() }}" defer></script>
@endif
@endsection
