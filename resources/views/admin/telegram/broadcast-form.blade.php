@extends('layouts.admin')
@php
    $editing = isset($broadcast) && $broadcast->exists;
    $buttons = old('buttons', $editing ? ($broadcast->buttons ?? []) : []);
    $isScheduled = old('scheduled', $editing ? ($broadcast->status === 'scheduled') : false);
    $scheduledAt = old('scheduled_at', $editing ? ($broadcast->scheduled_at?->format('Y-m-d\TH:i')) : '');
@endphp
@section('title', $editing ? 'Edit Broadcast' : 'Create Broadcast')
@section('content')
<div class="admin-page-heading telegram-form-heading">
    <div>
        <p>Telegram</p>
        <h1>{{ $editing ? 'Edit Broadcast' : 'Create Broadcast' }}</h1>
        <span>{{ $editing ? 'Update broadcast content, image, or buttons.' : 'Create a draft now or schedule it for all active subscribers.' }}</span>
    </div>
    <a href="{{ route('admin.telegram.index', ['tab'=>'broadcasts']) }}"><i class="fa-solid fa-arrow-left"></i> Go back</a>
</div>

@if($editing && $broadcast->sent_count > 0)
    <div class="telegram-connection-card" style="background: var(--info-soft); border-color: color-mix(in srgb, var(--info) 30%, transparent); margin-bottom: 20px;">
        <div class="telegram-connection-icon" style="background: var(--info);"><i class="fa-solid fa-rotate"></i></div>
        <div>
            <small style="color: var(--info); font-weight: 600;">Live Broadcast Sync</small>
            <strong>This broadcast has been delivered to {{ number_format($broadcast->sent_count) }} subscriber(s).</strong>
            <span>Saving your edits will automatically update the sent posts directly inside all recipient Telegram chats.</span>
        </div>
    </div>
@endif

<form class="telegram-form-card telegram-broadcast-form" method="POST" enctype="multipart/form-data" action="{{ $editing ? route('admin.telegram.broadcasts.update', $broadcast) : route('admin.telegram.broadcasts.store') }}" data-telegram-button-builder>
    @csrf
    @if($editing)
        @method('PUT')
    @endif

    <div class="telegram-form-section">
        <h2>Broadcast Details</h2>
        <label>Name
            <input name="name" value="{{ old('name', $editing ? $broadcast->name : '') }}" placeholder="e.g. Chicken Road Promo" required>
        </label>

        @if(! $editing)
            <label>Template
                <select name="telegram_template_id" required>
                    <option value="">Select template</option>
                    @foreach($templates as $template)
                        <option value="{{ $template->id }}" @selected((string)old('telegram_template_id') === (string)$template->id)>{{ $template->name }}</option>
                    @endforeach
                </select>
            </label>
        @else
            <label>Caption
                <textarea name="caption" rows="8" placeholder="e.g. <b>Big win alert!</b>\nPlay now..." maxlength="1024" required>{{ old('caption', $broadcast->caption) }}</textarea>
                <small>Supports Telegram HTML such as &lt;b&gt; and &lt;i&gt;. Media captions are limited to 1,024 characters.</small>
            </label>

            <div>
                <span class="telegram-label">Image <small>(Optional)</small></span>
                <label class="game-image-dropzone telegram-image-dropzone" data-image-dropzone>
                    <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" data-image-input>
                    <img src="{{ $broadcast->image_source ?? '' }}" alt="Broadcast preview" data-image-preview @if(!$broadcast->image_source) hidden @endif>
                    <i class="fa-solid fa-arrow-up-from-bracket game-upload-icon"></i>
                    <strong data-upload-title>Drag & drop image here, or click to select</strong>
                    <small>PNG, JPG, WEBP or GIF up to 10MB</small>
                </label>
                @if($broadcast->image_path)
                    <label class="game-remove-image"><input type="checkbox" name="remove_image" value="1"> Remove existing image</label>
                @endif
            </div>
        @endif

        @if(! $editing || in_array($broadcast->status, ['draft', 'scheduled'], true))
            <label class="telegram-schedule-toggle">
                <span><strong>Scheduled</strong><small>Send broadcast at a specific date and time</small></span>
                <input type="checkbox" name="scheduled" value="1" data-telegram-schedule-toggle @checked($isScheduled)>
            </label>
            <label data-telegram-schedule-field @if(!$isScheduled) hidden @endif>Scheduled date and time
                <input type="datetime-local" name="scheduled_at" value="{{ $scheduledAt }}">
            </label>
        @endif

        <aside class="telegram-recipient-note">
            <i class="fa-solid fa-users"></i>
            <div>
                <strong>{{ number_format($editing ? $broadcast->total_recipients : $activeSubscribers) }} {{ $editing ? 'total recipient(s)' : 'active recipient(s)' }}</strong>
                <span>{{ $editing ? 'Delivered to ' . number_format($broadcast->sent_count) . ' chat(s).' : 'The recipient snapshot is created when you send or schedule this broadcast.' }}</span>
            </div>
        </aside>
    </div>

    @if($editing)
        <div class="telegram-form-section telegram-buttons-section">
            <div class="telegram-section-title">
                <div><h2>Buttons</h2><p>Add URL or Telegram Web App buttons below the message.</p></div>
                <button type="button" data-add-telegram-button><i class="fa-solid fa-plus"></i> Add Button</button>
            </div>
            <div data-telegram-button-list>
                @foreach($buttons as $index => $button)
                    @include('admin.telegram.partials.button-row', ['index' => $index, 'button' => $button])
                @endforeach
            </div>
            <p class="telegram-buttons-empty" data-telegram-buttons-empty @if(count($buttons)) hidden @endif>No buttons added yet. Click "Add Button" to create one.</p>
            <template data-telegram-button-template>@include('admin.telegram.partials.button-row', ['index' => '__INDEX__', 'button' => ['text'=>'','type'=>'url','value'=>'']])</template>
        </div>
    @endif

    <footer>
        <a href="{{ route('admin.telegram.index', ['tab'=>'broadcasts']) }}">Cancel</a>
        <button type="submit">{{ $editing ? 'Save & Sync Broadcast' : 'Create Broadcast' }}</button>
    </footer>
</form>
@endsection
