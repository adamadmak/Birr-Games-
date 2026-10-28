<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * The screenshot a player attaches to a deposit, for the agent to check the
 * transfer against before approving.
 *
 * Kept in the database rather than on disk: the host replaces the container
 * on every deploy, and a screenshot that disappears is no evidence at all.
 * Every image is re-encoded on the way in (App\Services\DepositProofStore),
 * so what is stored is always a plain JPEG with no metadata.
 */
class DepositProof extends Model
{
    protected $fillable = ['agent_order_id', 'user_id', 'mime', 'bytes', 'width', 'height', 'data'];

    protected $hidden = ['data'];

    public const ALLOWED_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(AgentOrder::class, 'agent_order_id');
    }

    /**
     * Migrations are applied by hand on this host (App\Services\PendingMigrations),
     * so the table is created on first use, the way site_media is. The migration
     * file is guarded the same way; whichever runs first wins.
     */
    public static function ensureTable(): void
    {
        if (Schema::hasTable('deposit_proofs')) {
            return;
        }

        Schema::create('deposit_proofs', function ($table) {
            $table->id();
            $table->unsignedBigInteger('agent_order_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('mime', 40);
            $table->unsignedInteger('bytes')->default(0);
            $table->unsignedSmallInteger('width')->default(0);
            $table->unsignedSmallInteger('height')->default(0);
            $table->longText('data');
            $table->timestamps();
        });
    }

    public static function available(): bool
    {
        try {
            return Schema::hasTable('deposit_proofs');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Which of these orders have a screenshot, without loading any image data.
     *
     * @param  iterable<AgentOrder>  $orders
     * @return array<int, true> keyed by order id
     */
    public static function idsFor(iterable $orders): array
    {
        $ids = collect($orders)->pluck('id')->filter()->all();
        if ($ids === [] || ! self::available()) {
            return [];
        }

        return array_fill_keys(self::query()->whereIn('agent_order_id', $ids)->pluck('agent_order_id')->all(), true);
    }
}
