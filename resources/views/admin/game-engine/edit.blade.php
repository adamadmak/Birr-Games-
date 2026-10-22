@extends('layouts.admin')
@section('title', 'Edit Game')
@section('content')
<div class="game-edit-heading">
    <h1 data-testid="game-edit-title">Edit Game</h1>
    <div class="game-edit-heading-actions">
        <a href="{{ route('admin.game-engine.index') }}" class="game-back-pill" data-testid="game-edit-back"><i class="fa-solid fa-arrow-left"></i> Go back</a>
        <form method="POST" action="{{ route('admin.game-engine.games.destroy',$game) }}" data-confirm="Delete this game permanently?" >@csrf @method('DELETE')
            <button type="submit" class="game-delete-icon" data-testid="game-delete-button" aria-label="Delete {{ $game->title }}"><i class="fa-solid fa-trash-can"></i></button>
        </form>
    </div>
</div>

<form class="game-edit-form" method="POST" enctype="multipart/form-data" action="{{ route('admin.game-engine.games.update',$game) }}">@csrf @method('PUT')
    <section class="game-edit-card">
        <div class="form-block">
            <p class="form-block-label">Logo</p>
            <label class="game-image-dropzone" data-image-dropzone>
                <input type="file" name="image_upload" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif" data-image-input>
                <img src="{{ $game->image_source ?: '' }}" alt="{{ $game->title }} preview" data-image-preview @if(!$game->image_source) hidden @endif>
                <span class="game-upload-icon" data-upload-icon><i class="fa-solid fa-cloud-arrow-up"></i></span>
                <strong data-upload-title>{{ $game->image_source ? 'Click or drop a new logo to replace this one' : 'Drag & drop logo here, or click to select' }}</strong>
                <small>PNG, JPG, WebP or GIF up to 5 MB</small>
            </label>
            @if($game->image_source)<label class="game-remove-image"><input type="checkbox" name="remove_image" value="1"><span>Remove current logo</span></label>@endif
        </div>

        <div class="form-grid-3">
            <label class="field"><span>Name</span><input name="title" value="{{ old('title',$game->title) }}" placeholder="#Alice and the Mad Respin Party" required></label>
            <label class="field"><span>Slug</span><input name="slug" value="{{ old('slug',$game->slug) }}" placeholder="rbp-aliceandthemadrespinparty-rbp" required></label>
            <label class="field"><span>Description</span><input name="description" value="{{ old('description',$game->description) }}" placeholder="Join Alice in Wonderland for a whimsical adventure"></label>
        </div>

        @if($game->sync_source === 'bigbang')
            <p class="game-pin-note">
                <i class="fa-solid fa-thumbtack"></i>
                <span>
                    @if($game->pinnedFields())
                        <strong>Your settings are held against the provider feed.</strong>
                        The catalog refresh will not change {{ implode(', ', array_map(fn ($field) => str_replace('_', ' ', $field), $game->pinnedFields())) }} for this game.
                    @else
                        <strong>Saving this form holds your choices.</strong>
                        Once you save, a catalog refresh can no longer switch these settings back.
                    @endif
                </span>
            </p>
            @if($game->pinnedFields())
                <label class="game-option-card game-release-card">
                    <input type="checkbox" name="release_overrides" value="1">
                    <span><strong>Follow the provider again</strong><small>Drop the held settings and let the feed manage this game</small></span>
                </label>
            @endif
        @endif

        <div class="form-grid-3">
            <label class="game-option-card">
                <input type="checkbox" name="top_game" value="1" @checked(old('top_game',$game->top_game))>
                <span><strong>Top Game</strong><small>Shows in Trending. Stays on until you turn it off.</small></span>
            </label>
            <label class="game-option-card">
                <input type="checkbox" name="free_bet_supported" value="1" @checked(old('free_bet_supported',$game->free_bet_supported))>
                <span><strong>Free Bet Supported</strong><small>Allow free bets on this game</small></span>
            </label>
        </div>

        <div class="form-grid-3">
            <label class="game-option-card">
                <input type="checkbox" name="disabled" value="1" @checked(old('disabled',!$game->active))>
                <span><strong>Disabled</strong><small>Temporarily disable this game</small></span>
            </label>
            <label class="field"><span>Label</span><input name="label" value="{{ old('label',$game->label) }}" placeholder="label"></label>
            <label class="field"><span>Label Background Color</span><input name="label_background_color" value="{{ old('label_background_color',$game->label_background_color) }}" placeholder="label background color" pattern="#[0-9A-Fa-f]{6}"></label>
        </div>

        <div class="form-grid-3">
            <label class="field"><span>Provider</span><select name="provider_id" required>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected(old('provider_id',$game->provider_id)==$provider->id)>{{ $provider->name }}</option>@endforeach</select></label>
            <label class="field"><span>Category</span><select name="category_id" required>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(old('category_id',$game->category_id)==$category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <label class="field"><span>Order</span><input type="number" name="sort_order" value="{{ old('sort_order',$game->sort_order) }}" min="0" required></label>
        </div>

        <div class="form-grid-3">
            <label class="field"><span>Provider Game ID</span><input name="provider_game_id" value="{{ old('provider_game_id',$game->provider_game_id) }}"></label>
            <label class="field"><span>Launch Code</span><input name="external_launch_code" value="{{ old('external_launch_code',$game->external_launch_code) }}"></label>
            <label class="field"><span>Local Agent</span><select name="agent"><option value="">Provider or roadmap</option>@foreach($agentKeys as $agent)<option value="{{ $agent }}" @selected(old('agent',$game->agent)===$agent)>{{ $agent }}</option>@endforeach</select></label>
        </div>

        <div class="form-grid-3">
            <label class="field"><span>Tag</span><input name="tag" value="{{ old('tag',$game->tag) }}" placeholder="10 LINES"></label>
            <label class="field"><span>Fallback Art</span><input name="art" value="{{ old('art',$game->art) }}" placeholder="axum" required></label>
            <label class="field"><span>Fallback Symbol</span><input name="symbol" value="{{ old('symbol',$game->symbol) }}" placeholder="✦" required></label>
        </div>

        <div class="form-block">
            <p class="form-block-label">Lobbies</p>
            <select name="lobbies[]" multiple size="4" class="lobbies-select">@foreach($lobbies as $lobby)<option value="{{ $lobby->id }}" @selected(collect(old('lobbies',$game->lobbies->pluck('id')->all()))->contains($lobby->id))>{{ $lobby->name }}</option>@endforeach</select>
        </div>

        <div class="game-inline-flags">
            <label><input type="checkbox" name="crash" value="1" @checked(old('crash',$game->crash))> <span>Crash game</span></label>
            <label><input type="checkbox" name="content_server_disabled" value="1" @checked(old('content_server_disabled',!$game->content_server_enabled))> <span>Content server disabled</span></label>
        </div>

        <div class="game-edit-actions">
            <a href="{{ route('admin.game-engine.index') }}">Cancel</a>
            <button data-testid="game-save-button">Save changes</button>
        </div>
    </section>
</form>
@endsection
