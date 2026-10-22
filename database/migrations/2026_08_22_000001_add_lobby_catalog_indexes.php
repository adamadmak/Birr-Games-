<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the three catalogue reads the lobby makes on every request.
 *
 * The `games` table carried only its primary key, two uniques and the category
 * foreign key, so every lobby load sorted the whole catalogue on disk. Measured
 * against 5,000 games on MySQL, the three queries below accounted for 68ms of a
 * 76ms request, and all three were ordering or filtering on columns that had no
 * index at all.
 *
 * The column order in each index is the order the query uses them: equality
 * filters first, then the sort. That is what lets the engine read the rows out
 * in order rather than collecting them and sorting afterwards.
 *
 * EACH INDEX IS CHECKED BEFORE IT IS CREATED
 *
 * On this platform the schema and the migration history drift apart: deploys do
 * not reliably run `migrate`, so tables and indexes have been created by hand
 * from database/sql/*.sql without anything recording that it happened. These
 * three indexes already existed on one brand while this migration still counted
 * as pending, and re-creating one is a hard error — MySQL 1061, duplicate key
 * name — which aborts the whole run and blocks every migration queued behind
 * it. Skipping what is already there costs nothing and keeps one stale record
 * from holding up the rest.
 */
return new class extends Migration
{
    /** name => columns, in the order the query uses them. */
    private const INDEXES = [
        // The provider shelves: filter on active, partition by provider,
        // then order by top_game / sort_order / id. Also serves the
        // catalogue page, which sorts by the same three columns.
        'games_lobby_shelf_idx' => ['active', 'provider_id', 'top_game', 'sort_order', 'id'],

        // "N games online" in the deposit strip — a COUNT over the same
        // filter, which this lets the engine answer from the index alone.
        'games_playable_count_idx' => ['active', 'playable'],

        // The featured game: narrowed by sync_source and playable before
        // the ordering runs, instead of ordering the whole table first.
        'games_featured_idx' => ['sync_source', 'playable', 'active'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $columns) {
            if (Schema::hasIndex('games', $name)) {
                continue;
            }

            Schema::table('games', function (Blueprint $table) use ($name, $columns): void {
                $table->index($columns, $name);
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            if (! Schema::hasIndex('games', $name)) {
                continue;
            }

            Schema::table('games', function (Blueprint $table) use ($name): void {
                $table->dropIndex($name);
            });
        }
    }
};
