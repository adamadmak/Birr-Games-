<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Operator-uploaded images that must outlive a deploy.
 *
 * This host replaces the container's filesystem on every deploy and has no
 * `public/storage` symlink, so a file written to the public disk is either
 * unreachable (404) or gone after the next build. The database is the one
 * place that persists, so small site images (the lobby hero) live here,
 * base64-encoded in a text column, and are served by MediaController with
 * long cache headers.
 */
class SiteMedia extends Model
{
    protected $table = 'site_media';

    protected $fillable = ['slot', 'mime', 'data', 'bytes'];

    /** Only these ever leave the server, whatever a row claims to be. */
    public const ALLOWED_MIMES = ['image/webp', 'image/png', 'image/jpeg'];

    /**
     * Create the table if this brand's deploy never ran the migration.
     *
     * See App\Services\PendingMigrations: on this platform a migration in the
     * repository does not reliably reach the database, and a hero upload that
     * fails until someone remembers to press a button elsewhere is worse than
     * one that creates its own table. The migration file is guarded the same
     * way, so whichever runs first wins and the other is a no-op.
     */
    public static function ensureTable(): void
    {
        if (Schema::hasTable('site_media')) {
            return;
        }

        Schema::create('site_media', function ($table) {
            $table->id();
            $table->string('slot', 60)->unique();
            $table->string('mime', 60);
            $table->unsignedInteger('bytes')->default(0);
            $table->longText('data');
            $table->timestamps();
        });
    }

    public static function available(): bool
    {
        try {
            return Schema::hasTable('site_media');
        } catch (\Throwable) {
            return false;
        }
    }
}
