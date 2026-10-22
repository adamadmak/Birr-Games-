@extends('layouts.admin')
@section('title', 'Game Engine')
@section('content')
<div class="admin-page-heading engine-heading">
    <div><p>Casino</p><h1>Game Engine</h1><span>Manage the complete provider catalog and player-lobby publishing workflow.</span></div>
    <div class="engine-sync-actions">
        <form method="POST" action="{{ route('admin.game-engine.sync') }}">@csrf<button class="engine-sync">Sync BigBang</button></form>
        <form method="POST" action="{{ route('admin.game-engine.sync-qtech') }}">@csrf<button class="engine-sync">Sync QTech</button></form>
    </div>
</div>

<nav class="engine-tabs" aria-label="Game engine sections">
    @foreach(['game'=>'Game','category'=>'Category','provider'=>'Provider','lobby'=>'Lobby'] as $key=>$label)
        <a class="{{ $page===$key?'active':'' }}" href="{{ route('admin.game-engine.index',['page'=>$key]) }}">{{ $label }}</a>
    @endforeach
</nav>

<section class="engine-toolbar engine-filter-toolbar">
    <form method="GET" action="{{ route('admin.game-engine.index') }}">
        <input type="hidden" name="page" value="{{ $page }}">
        <label class="engine-search"><span>⌕</span><input type="search" name="search" value="{{ $search }}" placeholder="Search items..."></label>
        @if($page==='game')
            <select name="provider_id" aria-label="Provider"><option value="">Provider</option>@foreach($providers as $provider)<option value="{{ $provider->id }}" @selected($filters['provider_id']===$provider->id)>{{ $provider->name }}</option>@endforeach</select>
            <select name="crash" aria-label="Is Crash"><option value="">Is Crash</option><option value="1" @selected($filters['crash']===true)>Yes</option><option value="0" @selected($filters['crash']===false)>No</option></select>
            <select name="disabled" aria-label="Is Disabled"><option value="">Is Disabled</option><option value="1" @selected($filters['disabled']===true)>Yes</option><option value="0" @selected($filters['disabled']===false)>No</option></select>
            <select name="top_game" aria-label="Is Top Game"><option value="">Is Top Game</option><option value="1" @selected($filters['top_game']===true)>Yes</option><option value="0" @selected($filters['top_game']===false)>No</option></select>
            <select name="content_disabled" aria-label="Content Server Disabled"><option value="">Content Server Disabled</option><option value="1" @selected($filters['content_disabled']===true)>Yes</option><option value="0" @selected($filters['content_disabled']===false)>No</option></select>
        @elseif($page==='provider')
            <select name="disabled" aria-label="Is Disabled"><option value="">Is Disabled</option><option value="1" @selected($filters['disabled']===true)>Yes</option><option value="0" @selected($filters['disabled']===false)>No</option></select>
            <select name="content_disabled" aria-label="Content Server Disabled"><option value="">Content Server Disabled</option><option value="1" @selected($filters['content_disabled']===true)>Yes</option><option value="0" @selected($filters['content_disabled']===false)>No</option></select>
        @else
            <select name="active" aria-label="Is Active"><option value="">Is Active</option><option value="1" @selected($filters['active']===true)>Yes</option><option value="0" @selected($filters['active']===false)>No</option></select>
        @endif
        <button>Apply</button>
        @if($search || collect($filters)->filter(fn($value)=>$value!==null)->isNotEmpty())<a class="engine-reset" href="{{ route('admin.game-engine.index',['page'=>$page]) }}">Reset</a>@endif
    </form>

    <details class="engine-add-menu"><summary>+ Add New</summary><div class="engine-create-popover">
        @if($page==='provider')
            <form class="admin-form" method="POST" action="{{ route('admin.game-engine.providers.store') }}">@csrf
                <div class="popover-form-title"><i class="fa-solid fa-building"></i><div><strong>New provider</strong><small>Register a game studio or aggregator</small></div></div>
                <label>Name<input name="name" required></label><label>Description<textarea name="description"></textarea></label>
                <label>Accent<select name="accent"><option>cyan</option><option>blue</option><option>violet</option><option>gold</option></select></label>
                <label>API base URL<input type="url" name="api_base_url" placeholder="Added with provider credentials"></label>
                <label class="engine-check"><input type="checkbox" name="sync_enabled" value="1"> Enable API synchronization</label><button>Create provider</button>
            </form>
        @elseif($page==='category')
            <form class="admin-form" method="POST" action="{{ route('admin.game-engine.categories.store') }}">@csrf<div class="popover-form-title"><i class="fa-solid fa-layer-group"></i><div><strong>New category</strong><small>Group games by type</small></div></div><label>Name<input name="name" required></label><label>Description<textarea name="description"></textarea></label><label>Order<input type="number" name="sort_order" value="10" min="0" required></label><button>Create category</button></form>
        @elseif($page==='lobby')
            <form class="admin-form" method="POST" action="{{ route('admin.game-engine.lobbies.store') }}">@csrf<div class="popover-form-title"><i class="fa-solid fa-table-cells-large"></i><div><strong>New lobby row</strong><small>A curated section on the player lobby</small></div></div><label>Name<input name="name" required></label><label>Description<textarea name="description"></textarea></label><label>Order<input type="number" name="sort_order" value="10" min="0" required></label><label>Games<select name="games[]" multiple size="8">@foreach($games as $game)<option value="{{ $game->id }}">{{ $game->title }}</option>@endforeach</select></label><button>Create lobby</button></form>
        @else
            <form class="admin-form" method="POST" enctype="multipart/form-data" action="{{ route('admin.game-engine.games.store') }}">@csrf
                <div class="popover-form-title"><i class="fa-solid fa-dice"></i><div><strong>New game</strong><small>Add a game to the catalogue</small></div></div>
                <label>Title<input name="title" required></label><div><label>Provider<select name="provider_id">@foreach($providers as $provider)<option value="{{ $provider->id }}">{{ $provider->name }}</option>@endforeach</select></label><label>Category<select name="category_id">@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label></div>
                <label>Description<textarea name="description"></textarea></label><div><label>Provider game ID<input name="provider_game_id"></label><label>Launch code<input name="external_launch_code"></label></div>
                <div><label>Local agent<select name="agent"><option value="">Provider or roadmap</option>@foreach($agentKeys as $agent)<option value="{{ $agent }}">{{ $agent }}</option>@endforeach</select></label><label>Label<input name="label"></label></div>
                <div><label>Tag<input name="tag" value="NEW"></label><label>Game image<input type="file" name="image_upload" accept=".jpg,.jpeg,.png,.webp,.gif,image/jpeg,image/png,image/webp,image/gif"><small>JPG, PNG, WebP or GIF · max 5 MB</small></label></div><div><label>Art<input name="art" value="orbit" required></label><label>Symbol<input name="symbol" value="✦" required></label></div>
                <label>Order<input type="number" name="sort_order" value="0" min="0" required></label><label>Lobbies<select name="lobbies[]" multiple size="5">@foreach($lobbies as $lobby)<option value="{{ $lobby->id }}">{{ $lobby->name }}</option>@endforeach</select></label>
                <div class="engine-flag-grid"><label><input type="checkbox" name="top_game" value="1"> Top game</label><label><input type="checkbox" name="crash" value="1"> Crash</label><label><input type="checkbox" name="content_server_disabled" value="1"> Content server disabled</label></div><button>Create game</button>
            </form>
        @endif
    </div></details>
</section>

<section class="admin-panel engine-table-panel"><div class="admin-table-scroll"><table class="admin-table engine-data-table"><thead><tr>
    <th><input type="checkbox" aria-label="Select all"></th>
    @if($page==='game')<th>#</th><th>Name</th><th>Provider</th><th>Image</th><th>Slug</th><th>Label</th><th>Top game</th><th>Crash</th><th>Disabled</th><th>Content server</th><th>Date</th><th>Actions</th>
    @elseif($page==='provider')<th>#</th><th>Name</th><th>Description</th><th>Games</th><th>Disabled</th><th>Content server disabled</th><th>API sync</th><th>Date</th><th>Actions</th>
    @elseif($page==='category')<th>#</th><th>Name</th><th>Description</th><th>Slug</th><th>Games</th><th>Order</th><th>Active</th><th>Date</th><th>Actions</th>
    @else<th>#</th><th>Name</th><th>Description</th><th>Slug</th><th>Games</th><th>Order</th><th>Active</th><th>Date</th><th>Actions</th>@endif
</tr></thead><tbody>
@forelse($records as $record)<tr><td><input type="checkbox" aria-label="Select row {{ $record->id }}"></td><td>{{ $record->id }}</td>
    @if($page==='game')
        <td><div class="engine-game-cell"><p><strong>{{ $record->title }}</strong><small>{{ $record->agent ?: ($record->provider_game_id ? 'Provider game' : 'Roadmap') }}</small></p></div></td><td>{{ $record->provider->name }}</td>
        <td>@if($record->image_source)<img class="engine-game-image" src="{{ $record->image_source }}" alt="{{ $record->title }}">@else<span class="mini-art art-{{ $record->art }}">{{ $record->symbol }}</span>@endif</td><td><code>{{ $record->slug }}</code></td><td>{{ $record->label ?: $record->tag ?: '-' }}</td>
        <td><span class="engine-status {{ $record->top_game?'on':'' }}">{{ $record->top_game?'✓':'×' }}</span></td><td><span class="engine-status {{ $record->crash?'on':'' }}">{{ $record->crash?'✓':'×' }}</span></td><td><span class="engine-status {{ !$record->active?'off':'' }}">{{ !$record->active?'✓':'×' }}</span></td><td><span class="engine-status {{ $record->content_server_enabled?'on':'' }}">{{ $record->content_server_enabled?'✓':'×' }}</span></td><td>{{ $record->created_at->format('n/j/Y') }}</td>
        <td><a class="engine-edit-link" href="{{ route('admin.game-engine.games.edit',$record) }}" aria-label="Edit {{ $record->title }}">Edit</a></td>
    @elseif($page==='provider')
        <td><strong>{{ $record->name }}</strong><small class="cell-subtitle">{{ $record->slug }}</small></td><td>{{ $record->description ?: '-' }}</td><td>{{ number_format($record->games_count) }}</td><td><span class="engine-status {{ !$record->active?'off':'' }}">{{ !$record->active?'✓':'×' }}</span></td><td><span class="engine-status {{ !$record->content_server_enabled?'off':'' }}">{{ !$record->content_server_enabled?'✓':'×' }}</span></td><td>{{ $record->sync_enabled?'Enabled':'Local' }}</td><td>{{ $record->created_at->format('n/j/Y') }}</td>
        <td><details class="engine-row-editor"><summary aria-label="Edit {{ $record->name }}">Edit</summary><form class="admin-form" method="POST" action="{{ route('admin.game-engine.providers.update',$record) }}">@csrf @method('PUT')<label>Name<input name="name" value="{{ $record->name }}" required></label><label>Description<textarea name="description">{{ $record->description }}</textarea></label><label>Accent<select name="accent">@foreach(['cyan','blue','violet','gold'] as $accent)<option @selected($record->accent===$accent)>{{ $accent }}</option>@endforeach</select></label><label>API base URL<input type="url" name="api_base_url" value="{{ $record->api_base_url }}"></label><div class="engine-flag-grid"><label><input type="checkbox" name="sync_enabled" value="1" @checked($record->sync_enabled)> API sync</label><label><input type="checkbox" name="disabled" value="1" @checked(!$record->active)> Disabled</label><label><input type="checkbox" name="content_server_disabled" value="1" @checked(!$record->content_server_enabled)> Content disabled</label></div><button>Save provider</button></form></details></td>
    @elseif($page==='category')
        <td><strong>{{ $record->name }}</strong></td><td>{{ $record->description ?: '-' }}</td><td><code>{{ $record->slug }}</code></td><td>{{ number_format($record->games_count) }}</td><td>{{ $record->sort_order }}</td><td><span class="engine-status {{ $record->active?'on':'' }}">{{ $record->active?'✓':'×' }}</span></td><td>{{ $record->created_at->format('n/j/Y') }}</td>
        <td><details class="engine-row-editor"><summary aria-label="Edit {{ $record->name }}">Edit</summary><form class="admin-form" method="POST" action="{{ route('admin.game-engine.categories.update',$record) }}">@csrf @method('PUT')<label>Name<input name="name" value="{{ $record->name }}" required></label><label>Description<textarea name="description">{{ $record->description }}</textarea></label><label>Order<input type="number" name="sort_order" value="{{ $record->sort_order }}" min="0" required></label><label class="engine-check"><input type="checkbox" name="active" value="1" @checked($record->active)> Active</label><button>Save category</button></form></details></td>
    @else
        <td><strong>{{ $record->name }}</strong></td><td>{{ $record->description ?: '-' }}</td><td><code>{{ $record->slug }}</code></td><td>{{ number_format($record->games_count) }}</td><td>{{ $record->sort_order }}</td><td><span class="engine-status {{ $record->active?'on':'' }}">{{ $record->active?'✓':'×' }}</span></td><td>{{ $record->created_at->format('n/j/Y') }}</td>
        <td><details class="engine-row-editor"><summary aria-label="Edit {{ $record->name }}">Edit</summary><form class="admin-form" method="POST" action="{{ route('admin.game-engine.lobbies.update',$record) }}">@csrf @method('PUT')<label>Name<input name="name" value="{{ $record->name }}" required></label><label>Description<textarea name="description">{{ $record->description }}</textarea></label><label>Order<input type="number" name="sort_order" value="{{ $record->sort_order }}" min="0" required></label><label>Games<select name="games[]" multiple size="8">@foreach($games as $game)<option value="{{ $game->id }}" @selected($record->games->contains($game))>{{ $game->title }}</option>@endforeach</select></label><label class="engine-check"><input type="checkbox" name="active" value="1" @checked($record->active)> Active</label><button>Save lobby</button></form></details></td>
    @endif
</tr>@empty<tr><td colspan="14"><div class="admin-empty">
    {{-- Naming the filters that are narrowing the result. "No records match the
         current filters" was true but unhelpful: the Yes/No selects keep their
         value across searches, so a game could be excluded by a filter set
         minutes earlier while the search box looked like the only thing active.
         Someone searching for a crash game with Is Crash = No is told exactly
         that, and can clear it without losing what they typed. --}}
    @php
        $activeFilters = collect([
            'Is Crash' => $filters['crash'],
            'Is Disabled' => $filters['disabled'],
            'Is Top Game' => $filters['top_game'],
            'Content Server Disabled' => $filters['content_disabled'],
        ])->filter(fn ($value) => $value !== null)
          ->map(fn ($value, $label) => $label.' = '.($value ? 'Yes' : 'No'));
    @endphp
    @if ($activeFilters->isNotEmpty())
        <p>No {{ $page }} records match{{ $search !== '' ? ' “'.$search.'”' : '' }} with these filters: <strong>{{ $activeFilters->implode(', ') }}</strong>.</p>
        <p><a href="{{ request()->fullUrlWithQuery(['crash' => null, 'disabled' => null, 'top_game' => null, 'content_disabled' => null, 'provider_id' => null]) }}">Clear the filters and search again</a></p>
    @elseif ($search !== '')
        <p>No {{ $page }} records match “{{ $search }}”.</p>
    @else
        <p>No {{ $page }} records yet.</p>
    @endif
</div></td></tr>@endforelse
</tbody></table></div>
<div class="engine-footer"><span>Showing {{ $records->count() }} of {{ number_format($records->total()) }} record(s).</span><form method="GET">@foreach(request()->except('per_page','page') as $key=>$value)@if(!is_array($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif @endforeach<input type="hidden" name="page" value="{{ $page }}"><label>Page size <select name="per_page" onchange="this.form.submit()"><option @selected($perPage===10)>10</option><option @selected($perPage===25)>25</option><option @selected($perPage===50)>50</option></select></label></form><div class="simple-pagination">@if($records->onFirstPage())<span>‹</span>@else<a href="{{ $records->previousPageUrl() }}">‹</a>@endif<strong>Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</strong>@if($records->hasMorePages())<a href="{{ $records->nextPageUrl() }}">›</a>@else<span>›</span>@endif</div></div>
</section>
@endsection
