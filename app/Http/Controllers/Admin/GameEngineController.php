<?php

namespace App\Http\Controllers\Admin;

use App\GameAgents\GameAgentManager;
use App\Models\Game;
use App\Models\GameCategory;
use App\Models\GameLobby;
use App\Models\GameProvider;
use App\Http\Controllers\BigBangCatalogSyncController;
use App\Services\BigBangCasinoClient;
use App\Services\BigBangCatalogSynchronizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GameEngineController
{
    private const PAGES = ['game', 'category', 'provider', 'lobby'];

    public function index(Request $request, GameAgentManager $agents): View
    {
        $page = in_array($request->string('page')->toString(), self::PAGES, true) ? $request->string('page')->toString() : 'game';
        $search = $request->string('search')->trim()->toString();
        $perPage = in_array($request->integer('per_page'), [10, 25, 50], true) ? $request->integer('per_page') : 10;
        $filters = [
            'provider_id' => $request->integer('provider_id') ?: null,
            'crash' => $request->has('crash') ? $request->boolean('crash') : null,
            'disabled' => $request->has('disabled') ? $request->boolean('disabled') : null,
            'top_game' => $request->has('top_game') ? $request->boolean('top_game') : null,
            'content_disabled' => $request->has('content_disabled') ? $request->boolean('content_disabled') : null,
            'active' => $request->has('active') ? $request->boolean('active') : null,
        ];

        $records = match ($page) {
            'provider' => GameProvider::query()->withCount('games')
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
                ->when($filters['disabled'] !== null, fn ($q) => $q->where('active', !$filters['disabled']))
                ->when($filters['content_disabled'] !== null, fn ($q) => $q->where('content_server_enabled', !$filters['content_disabled']))
                ->orderBy('name')->paginate($perPage),
            'category' => GameCategory::query()->withCount('games')
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
                ->when($filters['active'] !== null, fn ($q) => $q->where('active', $filters['active']))
                ->orderBy('sort_order')->paginate($perPage),
            'lobby' => GameLobby::query()->withCount('games')
                ->when($search, fn ($q) => $q->where(fn ($n) => $n->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")))
                ->when($filters['active'] !== null, fn ($q) => $q->where('active', $filters['active']))
                ->orderBy('sort_order')->paginate($perPage),
            // Matched exactly as the player-facing search matches, via the
            // shared helper: an operator who can see a game on the site has to
            // be able to find it here by the same name. A plain LIKE could not,
            // because feed titles are run together and people type spaces.
            default => Game::query()->with(['provider', 'category', 'lobbies'])
                ->when($search, fn ($q) => \App\Support\GameSearch::apply($q, $search))
                ->when($filters['provider_id'], fn ($q, $providerId) => $q->where('provider_id', $providerId))
                ->when($filters['crash'] !== null, fn ($q) => $q->where('crash', $filters['crash']))
                ->when($filters['disabled'] !== null, fn ($q) => $q->where('active', !$filters['disabled']))
                ->when($filters['top_game'] !== null, fn ($q) => $q->where('top_game', $filters['top_game']))
                ->when($filters['content_disabled'] !== null, fn ($q) => $q->where('content_server_enabled', !$filters['content_disabled']))
                ->orderBy('title')->paginate($perPage),
        };

        return view('admin.game-engine.index', [
            'page' => $page, 'search' => $search, 'perPage' => $perPage, 'filters' => $filters,
            'records' => $records->withQueryString(),
            'providers' => in_array($page, ['game', 'provider'], true) ? GameProvider::query()->orderBy('name')->get() : collect(),
            'categories' => $page === 'game' ? GameCategory::query()->orderBy('sort_order')->get() : collect(),
            'lobbies' => $page === 'game' ? GameLobby::query()->orderBy('sort_order')->get() : collect(),
            'games' => $page === 'lobby' ? Game::query()->select(['id', 'title'])->orderBy('title')->get() : collect(),
            'agentKeys' => [],
        ]);
    }

    public function syncQtech(\App\Services\QtechClient $client, \App\Services\QtechCatalogSynchronizer $synchronizer): RedirectResponse
    {
        if (! $client->configured()) {
            return back()->withErrors(['sync' => 'Add the QTech API username and password under Settings → System to synchronize QTech games.']);
        }

        // Same lock as the timer endpoint, for the same reason as BigBang's.
        $lock = Cache::lock(\App\Http\Controllers\QtechCatalogSyncController::LOCK_KEY, 900);
        if (! $lock->get()) {
            return back()->withErrors(['sync' => 'A QTech catalog sync is already running. Try again in a minute.']);
        }

        try {
            $result = $synchronizer->sync(budgetSeconds: 45.0);
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'QTech could not be reached. Check the API base URL under Settings → System.';

            return back()->withErrors(['sync' => $message]);
        } finally {
            $lock->release();
        }

        return back()->with('admin_status', sprintf(
            'QTech synchronized: %d created, %d updated, %d retired, %d provider(s) hidden, %d provider(s) restored. Read %d of %s games across %d page(s).%s',
            $result['created'], $result['updated'], $result['retired'],
            $result['providers_disabled'], $result['providers_enabled'],
            $result['fetched'], $result['expected'] === null ? 'an unreported number of' : $result['expected'],
            $result['pages'],
            $result['truncated']
                ? ' The read stopped early, so nothing was retired — run it again or let the timer continue.'
                : ''
        ));
    }

    public function sync(BigBangCasinoClient $client, BigBangCatalogSynchronizer $synchronizer): RedirectResponse
    {
        if (\App\Support\BigBang::retired()) {
            return back()->withErrors(['sync' => 'BigBang has been retired. Turn it back on under Settings → System to synchronize its games again.']);
        }

        if (!$client->configured()) {
            return back()->withErrors(['sync' => 'Add a current BigBang API key to synchronize provider games.']);
        }

        // Same lock as the timer endpoint: this button and the hourly timer
        // drive the same synchronizer, and without sharing the lock an admin
        // click during a timer run meant two writers interleaving — the loser's
        // half-built view of the feed could retire live games.
        $lock = Cache::lock(BigBangCatalogSyncController::LOCK_KEY, 900);
        if (!$lock->get()) {
            return back()->withErrors(['sync' => 'A catalog sync is already running (likely the hourly timer). Try again in a minute.']);
        }

        try {
            // Budgeted for the same reason the HTTP timer endpoint is: this click
            // runs inside a web request, and an unbudgeted full read held a worker
            // for minutes. A truncated run retires nothing and the next click — or
            // the hourly timer — carries on where it stopped.
            $result = $synchronizer->sync(budgetSeconds: 45.0);
        } catch (\Throwable $exception) {
            report($exception);
            $message = $exception instanceof \RuntimeException
                ? $exception->getMessage()
                : 'BigBang could not be reached. Check the API base URL shown in Portal → Integration.';
            return back()->withErrors(['sync' => $message]);
        } finally {
            $lock->release();
        }

        return back()->with('admin_status', sprintf(
            'BigBang synchronized: %d created, %d updated, %d retired, %d provider(s) hidden, %d provider(s) restored. Read %d of %s games across %d page(s).%s',
            $result['created'], $result['updated'], $result['retired'] ?? 0,
            $result['providers_disabled'] ?? 0, $result['providers_enabled'] ?? 0,
            $result['fetched'] ?? 0, $result['expected'] === null ? 'an unreported number of' : $result['expected'],
            $result['pages'],
            ($result['truncated'] ?? false)
                ? ' The read stopped early (time budget or page ceiling), so nothing was retired — run sync again or let the hourly timer continue; withdrawn providers stay visible until a full read completes.'
                : ''
        ));
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $data = $this->providerData($request);
        GameProvider::create($data + ['slug' => Str::slug($data['name']), 'active' => true]);
        return back()->with('admin_status', 'Provider created.');
    }

    public function updateProvider(Request $request, GameProvider $provider): RedirectResponse
    {
        $data = $this->providerData($request, $provider);
        $provider->update($data + ['slug' => Str::slug($data['name'])]);
        return back()->with('admin_status', 'Provider updated.');
    }

    public function toggleProvider(GameProvider $provider): RedirectResponse
    {
        $provider->update(['active' => !$provider->active]);
        return back()->with('admin_status', 'Provider status updated.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->categoryData($request);
        GameCategory::create($data + ['slug' => Str::slug($data['name']), 'active' => true]);
        return back()->with('admin_status', 'Category created.');
    }

    public function updateCategory(Request $request, GameCategory $category): RedirectResponse
    {
        $data = $this->categoryData($request, $category);
        $category->update($data + ['slug' => Str::slug($data['name']), 'active' => $request->boolean('active')]);
        return back()->with('admin_status', 'Category updated.');
    }

    public function toggleCategory(GameCategory $category): RedirectResponse
    {
        $category->update(['active' => !$category->active]);
        return back()->with('admin_status', 'Category status updated.');
    }

    public function storeLobby(Request $request): RedirectResponse
    {
        $data = $this->lobbyData($request);
        $lobby = GameLobby::create(collect($data)->except('games')->all() + ['slug' => Str::slug($data['name']), 'active' => true]);
        $lobby->games()->sync($data['games'] ?? []);
        return back()->with('admin_status', 'Lobby created and published.');
    }

    public function updateLobby(Request $request, GameLobby $lobby): RedirectResponse
    {
        $data = $this->lobbyData($request, $lobby);
        $lobby->update(collect($data)->except('games')->all() + ['slug' => Str::slug($data['name']), 'active' => $request->boolean('active')]);
        $lobby->games()->sync($data['games'] ?? []);
        return back()->with('admin_status', 'Lobby updated.');
    }

    public function toggleLobby(GameLobby $lobby): RedirectResponse
    {
        $lobby->update(['active' => !$lobby->active]);
        return back()->with('admin_status', 'Lobby status updated.');
    }

    public function storeGame(Request $request, GameAgentManager $agents): RedirectResponse
    {
        $data = $this->gameData($request, $agents);
        if ($request->hasFile('image_upload')) {
            $data['image_path'] = $request->file('image_upload')->store('game-images', 'public');
            $data['image_url'] = null;
        }

        $game = Game::create(collect($data)->except(['lobbies', 'image_upload'])->all() + [
            'slug' => Str::slug(($data['slug'] ?? null) ?: $data['title']), 'playable' => !empty($data['agent']), 'active' => true,
            'top_game' => $request->boolean('top_game'), 'crash' => $request->boolean('crash'),
            'free_bet_supported' => $request->boolean('free_bet_supported'),
            'content_server_enabled' => !$request->boolean('content_server_disabled'), 'sync_source' => 'local',
        ]);
        $game->lobbies()->sync($data['lobbies'] ?? []);
        return back()->with('admin_status', 'Game created and connected to the player lobby.');
    }

    public function editGame(Game $game, GameAgentManager $agents): View
    {
        return view('admin.game-engine.edit', [
            'game' => $game->load('lobbies'),
            'providers' => GameProvider::query()->orderBy('name')->get(),
            'categories' => GameCategory::query()->orderBy('sort_order')->get(),
            'lobbies' => GameLobby::query()->orderBy('sort_order')->get(),
            'agentKeys' => [],
        ]);
    }

    public function updateGame(Request $request, Game $game, GameAgentManager $agents): RedirectResponse
    {
        $data = $this->gameData($request, $agents, $game);
        $original = $game->only(Game::SYNC_MANAGED);
        $oldImagePath = $game->image_path;

        if ($request->hasFile('image_upload')) {
            $data['image_path'] = $request->file('image_upload')->store('game-images', 'public');
            $data['image_url'] = null;
        } elseif ($request->boolean('remove_image')) {
            $data['image_path'] = null;
            $data['image_url'] = null;
        }

        $game->update(collect($data)->except(['lobbies', 'image_upload'])->all() + [
            'slug' => Str::slug(($data['slug'] ?? null) ?: $data['title']), 'playable' => !empty($data['agent']) || !empty($data['external_launch_code']),
            'active' => !$request->boolean('disabled'), 'top_game' => $request->boolean('top_game'),
            'free_bet_supported' => $request->boolean('free_bet_supported'),
            'crash' => $request->boolean('crash'), 'content_server_enabled' => !$request->boolean('content_server_disabled'),
        ]);

        if (($request->hasFile('image_upload') || $request->boolean('remove_image')) && $oldImagePath) {
            Storage::disk('public')->delete($oldImagePath);
        }

        $game->lobbies()->sync($data['lobbies'] ?? []);

        // Remember what was decided here so the next catalog refresh cannot undo it. The
        // toggles count as an explicit choice every time, because the form states each one
        // outright. Text and numbers are pinned only when they actually changed, so genuine
        // provider updates keep coming through for everything left alone.
        if ($request->boolean('release_overrides')) {
            $game->releasePins();
        } else {
            $fresh = $game->fresh();
            $changed = collect(Game::SYNC_MANAGED)
                ->filter(fn (string $field) => $this->valueChanged($original[$field] ?? null, $fresh->{$field}))
                ->all();

            $game->pinFields(array_merge(Game::EXPLICIT_TOGGLES, $changed));
        }

        return back()->with('admin_status', 'Game updated. These settings now stay put through catalog refreshes.');
    }

    /** Loose comparison, because a checkbox arrives as a bool and the column may hold 0 or 1. */
    private function valueChanged(mixed $before, mixed $after): bool
    {
        if (is_bool($before) || is_bool($after)) {
            return (bool) $before !== (bool) $after;
        }

        return (string) $before !== (string) $after;
    }

    public function destroyGame(Game $game): RedirectResponse
    {
        if ($game->image_path) {
            Storage::disk('public')->delete($game->image_path);
        }

        $game->delete();

        return redirect()->route('admin.game-engine.index')->with('admin_status', 'Game deleted.');
    }

    public function toggleGame(Game $game): RedirectResponse
    {
        $game->update(['active' => !$game->active]);
        return back()->with('admin_status', 'Game visibility updated.');
    }

    private function providerData(Request $request, ?GameProvider $provider = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('game_providers', 'name')->ignore($provider)],
            'description' => ['nullable', 'string', 'max:300'], 'accent' => ['required', Rule::in(['cyan', 'blue', 'violet', 'gold'])],
            'api_base_url' => ['nullable', 'url', 'max:255'],
        ]);
        return $data + [
            'active' => !$request->boolean('disabled'),
            'content_server_enabled' => !$request->boolean('content_server_disabled'),
            'sync_enabled' => $request->boolean('sync_enabled'),
        ];
    }

    private function categoryData(Request $request, ?GameCategory $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('game_categories', 'name')->ignore($category)],
            'description' => ['nullable', 'string', 'max:300'], 'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    private function lobbyData(Request $request, ?GameLobby $lobby = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('game_lobbies', 'name')->ignore($lobby)],
            'description' => ['nullable', 'string', 'max:300'], 'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
            'games' => ['nullable', 'array'], 'games.*' => ['integer', 'exists:games,id'],
        ]);
    }

    private function gameData(Request $request, GameAgentManager $agents, ?Game $game = null): array
    {
        $this->ensureValidUpload($request, 'image_upload', 5);

        return $request->validate([
            'title' => ['required', 'string', 'max:120', Rule::unique('games', 'title')->ignore($game)],
            'slug' => ['nullable', 'string', 'max:140', Rule::unique('games', 'slug')->ignore($game)],
            'provider_id' => ['required', 'exists:game_providers,id'], 'category_id' => ['required', 'exists:game_categories,id'],
            'provider_game_id' => ['nullable', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'tag' => ['nullable', 'string', 'max:30'], 'label' => ['nullable', 'string', 'max:60'],
            'label_background_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'art' => ['required', 'string', 'max:30'], 'symbol' => ['required', 'string', 'max:10'],
            'image_upload' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'agent' => ['nullable', Rule::in($agents->keys())], 'external_launch_code' => ['nullable', 'string', 'max:180'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:100000'], 'lobbies' => ['nullable', 'array'], 'lobbies.*' => ['integer', 'exists:game_lobbies,id'],
        ]);
    }

    private function ensureValidUpload(Request $request, string $field = 'image_upload', int $maxMb = 5): void
    {
        if ($request->hasFile($field)) {
            $file = $request->file($field);
            if (!$file->isValid()) {
                $error = $file->getError();
                $message = match ($error) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "The uploaded image exceeds the maximum allowed file size ({$maxMb}MB).",
                    UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary folder for upload.',
                    UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write uploaded file to disk.',
                    default => 'The image failed to upload. Please select a valid image file under '.$maxMb.'MB.',
                };
                throw ValidationException::withMessages([$field => $message]);
            }
        }
    }
}
