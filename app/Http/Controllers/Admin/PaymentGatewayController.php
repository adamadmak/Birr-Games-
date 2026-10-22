<?php

namespace App\Http\Controllers\Admin;

use App\Models\AgentOrder;
use App\Models\PaymentGateway;
use App\Models\WalletTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PaymentGatewayController
{
    public function index(Request $request): View
    {
        $type = in_array($request->string('type')->toString(), ['bank', 'provider'], true) ? $request->string('type')->toString() : 'bank';
        return view('admin.payment-gateways.index', [
            'type' => $type,
            'gateways' => PaymentGateway::query()->where('type', $type)->withCount('agentAccounts')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:60', 'unique:payment_gateways,code'],
            'type' => ['required', 'in:bank,provider'],
            'account_label' => ['nullable', 'string', 'max:120'],
            'supports_deposit' => ['nullable', 'boolean'],
            'supports_withdrawal' => ['nullable', 'boolean'],
        ]);
        $validated['code'] = Str::slug($validated['code'] ?: $validated['name'], '_');
        $validated['supports_deposit'] = $request->boolean('supports_deposit');
        $validated['supports_withdrawal'] = $request->boolean('supports_withdrawal');
        PaymentGateway::create($validated + ['active' => true]);
        return back()->with('admin_status', 'Payment gateway created.');
    }

    public function toggle(PaymentGateway $gateway): RedirectResponse
    {
        $gateway->update(['active' => !$gateway->active]);
        return back()->with('admin_status', 'Gateway status updated.');
    }

    /**
     * Delete a gateway no money has moved through.
     *
     * What must not happen is losing the record: `agent_orders` and
     * `wallet_transactions` hold the gateway with nullOnDelete, so deleting one
     * that HAS settled payments blanks the method out of each of them — a
     * payment record that no longer says how the money moved, with nothing to
     * show something was removed. That case is refused.
     *
     * Attached agent accounts are NOT a reason to refuse, though they were at
     * first, and that made this button useless: every gateway an operator
     * actually uses has agent accounts on it, so Delete refused every time and
     * only a gateway nobody had finished setting up could go. An agent account
     * is routing information — where to send money today — and an order copies
     * the name and number it used at the moment it is taken, so the cascade
     * costs the record nothing. They are counted into the confirmation instead,
     * so the operator knows what goes with it.
     */
    public function destroy(PaymentGateway $gateway): RedirectResponse
    {
        $orders = AgentOrder::query()->where('payment_gateway_id', $gateway->id)->count();
        $transactions = WalletTransaction::query()->where('payment_gateway_id', $gateway->id)->count();

        if ($orders > 0 || $transactions > 0) {
            $holds = [];
            if ($orders > 0) {
                $holds[] = number_format($orders).' '.($orders === 1 ? 'order' : 'orders');
            }
            if ($transactions > 0) {
                $holds[] = number_format($transactions).' wallet '.($transactions === 1 ? 'transaction' : 'transactions');
            }

            return back()->withErrors(['gateway' => 'Money has moved through '.$gateway->name.': '.implode(' and ', $holds)
                .'. Deleting it would blank the method out of that history rather than remove it, so it is refused.'
                .' Disable the gateway instead — players stop seeing it and the records keep their meaning.']);
        }

        $accounts = $gateway->agentAccounts()->count();
        $name = $gateway->name;
        $gateway->delete();

        return back()->with('admin_status', 'Deleted payment gateway '.$name
            .($accounts > 0 ? ', and the '.$accounts.' agent payment '.($accounts === 1 ? 'account' : 'accounts').' routed through it.' : '.'));
    }
}
