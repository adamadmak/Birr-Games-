<dialog class="reference-member-modal member-edit-modal" id="member-edit-{{ $member->id }}" data-member-modal aria-labelledby="member-edit-title-{{ $member->id }}">
    <form method="POST" action="{{ route('admin.members.update',$member) }}">@csrf @method('PATCH')<header><h2 id="member-edit-title-{{ $member->id }}">Edit Member</h2><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header><div class="reference-member-modal-body"><div class="reference-form-grid three"><label>First Name<input name="first_name" value="{{ $member->first_name }}" required></label><label>Last Name<input name="last_name" value="{{ $member->last_name }}"></label><label>Username<input name="username" value="{{ $member->username }}" maxlength="32" placeholder="Player handle"></label><label>Phone Number<input name="phone" value="{{ $member->phone }}" required placeholder="09XXXXXXXX"></label><label>Withdrawal Cap<input type="number" name="withdrawal_cap" value="{{ $member->withdrawal_cap }}" min="0" placeholder="Blank for no cap"></label><label>Transfer Cap<input type="number" name="transfer_cap" value="{{ $member->transfer_cap }}" min="0" placeholder="Blank for no cap"></label><label>Deposit Cap<input type="number" name="deposit_cap" value="{{ $member->deposit_cap }}" min="0" placeholder="Blank for no cap"></label></div><div class="reference-form-switches"><label><span>Contact Confirmed</span><input type="checkbox" name="contact_confirmed" value="1" @checked($member->contact_confirmed)><i></i></label><label><span>Wallet Active</span><input type="checkbox" name="active" value="1" @checked($member->active)><i></i></label></div></div><footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit">Save</button></footer></form>
</dialog>

<dialog class="reference-member-modal compact" id="member-deposit-{{ $member->id }}" data-member-modal aria-labelledby="member-deposit-title-{{ $member->id }}">
    <form method="POST" action="{{ route('admin.members.deposit',$member) }}">@csrf<header><div><h2 id="member-deposit-title-{{ $member->id }}">Deposit for Member</h2><p>Specify the deposit amount and choose withdrawable status.</p></div><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header><div class="reference-member-modal-body"><label>Amount (ETB)<input type="number" name="amount" min="1" max="1000000000" placeholder="Enter amount" required></label><label>Withdrawable Status<select name="withdrawable_status" required><option value="">Select withdrawable status</option><option value="withdrawable">Withdrawable</option><option value="locked">Bonus / Locked</option></select></label></div><footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit">Save</button></footer></form>
</dialog>

<dialog class="reference-member-modal compact" id="member-password-{{ $member->id }}" data-member-modal aria-labelledby="member-password-title-{{ $member->id }}">
    <form method="POST" action="{{ route('admin.members.password',$member) }}">@csrf @method('PATCH')<header><h2 id="member-password-title-{{ $member->id }}">Reset Password — {{ $member->phone ?: $member->name }}</h2><button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header><div class="reference-member-modal-body"><label>New Password<input type="password" name="password" minlength="8" placeholder="Enter password" required></label><label>Confirm Password<input type="password" name="password_confirmation" minlength="8" placeholder="Confirm password" required></label></div><footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit">Reset Password</button></footer></form>
</dialog>

@if(($canDelete ?? false) && ! ($archived ?? false))
<dialog class="reference-member-modal compact member-danger-modal" id="member-archive-{{ $member->id }}" data-member-modal aria-labelledby="member-archive-title-{{ $member->id }}">
    <form method="POST" action="{{ route('admin.members.destroy', $member) }}">@csrf @method('DELETE')
        <header>
            <div><small>Irreversible for the player</small><h2 id="member-archive-title-{{ $member->id }}">Archive {{ $member->name }}?</h2><p>{{ $member->phone ?: 'No phone' }}{{ $member->username ? ' · @'.$member->username : '' }}</p></div>
            <button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="reference-member-modal-body">
            <ul class="member-danger-list">
                <li><i class="fa-solid fa-ban"></i> The player can no longer sign in, on the web or through Telegram.</li>
                <li><i class="fa-solid fa-link-slash"></i> Their Telegram account is unlinked and live login links are revoked.</li>
                <li><i class="fa-solid fa-shield-halved"></i> Wallet transactions, orders and game rounds are kept for audits.</li>
                <li><i class="fa-solid fa-rotate-left"></i> You can restore the account from the Archived tab.</li>
            </ul>
            @if((float) $member->credits > 0)
                <p class="member-danger-block"><i class="fa-solid fa-circle-exclamation"></i> This wallet holds {{ \App\Support\Money::format($member->credits, 2) }}. Pay it out or zero the balance first — archiving will be refused.</p>
            @endif
        </div>
        <footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit" class="danger"><i class="fa-solid fa-box-archive"></i> Archive member</button></footer>
    </form>
</dialog>
@endif

@if(($canDelete ?? false) && ($archived ?? false))
<dialog class="reference-member-modal compact member-danger-modal" id="member-purge-{{ $member->id }}" data-member-modal aria-labelledby="member-purge-title-{{ $member->id }}">
    <form method="POST" action="{{ route('admin.members.purge', $member) }}">@csrf @method('DELETE')
        <header>
            <div><small>Cannot be undone</small><h2 id="member-purge-title-{{ $member->id }}">Delete {{ $member->name }} permanently?</h2><p>Archived {{ $member->deleted_at?->format('M j, Y') }}</p></div>
            <button type="button" data-member-modal-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="reference-member-modal-body">
            <ul class="member-danger-list">
                <li><i class="fa-solid fa-trash"></i> The record is removed from the database for good.</li>
                <li><i class="fa-solid fa-phone"></i> The phone number and username become available again.</li>
                <li><i class="fa-solid fa-shield-halved"></i> Only allowed while the account holds no transactions, orders or rounds — otherwise it stays archived.</li>
            </ul>
        </div>
        <footer><button type="button" class="secondary" data-member-modal-close>Cancel</button><button type="submit" class="danger"><i class="fa-solid fa-trash"></i> Delete permanently</button></footer>
    </form>
</dialog>
@endif
