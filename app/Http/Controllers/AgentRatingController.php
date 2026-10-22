<?php

namespace App\Http\Controllers;

use App\Models\AgentOrder;
use App\Models\AgentRating;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A player's verdict on the agent who handled one of their orders.
 *
 * Every guard here is about entitlement rather than convenience: the rating is
 * evidence of service, so it may only be left by the person whose money moved,
 * about an order that actually completed, once. The uniqueness is enforced in the
 * schema as well — a double submission races the check, not the constraint.
 */
class AgentRatingController
{
    public function store(Request $request, AgentOrder $order): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'score' => ['required', 'integer', 'between:'.AgentRating::MIN_SCORE.','.AgentRating::MAX_SCORE],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        if ($order->status !== 'success' || ! $order->agent_id) {
            return back()->with('error', __('Only a completed order handled by an agent can be rated.'));
        }

        if ($order->rating()->exists()) {
            return back()->with('error', __('You have already rated this transaction.'));
        }

        AgentRating::create([
            'agent_order_id' => $order->id,
            'agent_id' => $order->agent_id,
            'user_id' => $request->user()->id,
            'score' => $data['score'],
            'comment' => $data['comment'] ?? null,
        ]);

        return back()->with('status', __('Thank you — your rating was recorded.'));
    }
}
