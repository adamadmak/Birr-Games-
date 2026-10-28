<?php

namespace App\Http\Controllers;

use App\Models\AgentOrder;
use App\Models\DepositProof;
use App\Services\AgentNotifier;
use App\Services\DepositProofStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Payment screenshots on deposits: the player attaches one, and the player,
 * the assigned agent and an administrator can look at it. Nobody else can.
 */
class DepositProofController
{
    /** A player adds (or replaces) the screenshot on their own pending deposit. */
    public function attach(Request $request, AgentOrder $order, DepositProofStore $store, AgentNotifier $notifier): RedirectResponse
    {
        abort_unless($order->user_id === $request->user()->id && $order->type === 'deposit', 404);

        $request->validate([
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ], [
            'proof.mimes' => __('Upload the screenshot as a JPG, PNG or WEBP picture.'),
            'proof.max' => __('That picture is larger than 10 MB. Take a screenshot instead of a photo.'),
        ]);

        if ($order->status !== 'pending') {
            return redirect()->route('player.wallet', ['tab' => 'deposit'])
                ->with('error', __('This deposit has already been handled, so it no longer takes a screenshot.'));
        }

        try {
            $store->store($order, $request->file('proof'));
        } catch (InvalidArgumentException $exception) {
            return redirect()->route('player.wallet', ['tab' => 'deposit'])->with('error', $exception->getMessage());
        }

        $notifier->proofAdded($order->fresh(['agent', 'user', 'gateway']));

        return redirect()->route('player.wallet', ['tab' => 'deposit'])
            ->with('status', __('Screenshot attached. Your agent can now check it against the transfer.'));
    }

    public function forPlayer(Request $request, AgentOrder $order): Response
    {
        abort_unless($order->user_id === $request->user()->id && $order->type === 'deposit', 404);

        return $this->serve($order);
    }

    public function forAgent(Request $request, AgentOrder $order): Response
    {
        abort_unless($order->type === 'deposit' && $order->agent_id === $request->user('agent')?->id, 404);

        return $this->serve($order);
    }

    public function forAdmin(AgentOrder $order): Response
    {
        abort_unless($order->type === 'deposit', 404);

        return $this->serve($order);
    }

    private function serve(AgentOrder $order): Response
    {
        $proof = DepositProof::available()
            ? DepositProof::query()->where('agent_order_id', $order->id)->first(['id', 'mime', 'data'])
            : null;
        abort_if($proof === null || ! in_array($proof->mime, DepositProof::ALLOWED_MIMES, true), 404);

        $binary = base64_decode((string) $proof->data, true);
        if ($binary === false || $binary === '') {
            Log::warning('A stored deposit screenshot could not be decoded.', ['order_id' => $order->id]);
            abort(404);
        }

        // Private: a screenshot of somebody's bank app is not for any cache.
        return response($binary, 200, [
            'Content-Type' => $proof->mime,
            'Content-Length' => (string) strlen($binary),
            'Content-Disposition' => 'inline; filename="deposit-'.$order->id.'.jpg"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
