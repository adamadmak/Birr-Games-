@extends('layouts.admin')
@section('title', $template->exists ? 'Edit Template' : 'Create Template')
@section('content')
@php $editing = $template->exists; $buttons = old('buttons', $template->buttons ?? []); @endphp
<div class="admin-page-heading telegram-form-heading"><div><p>Telegram</p><h1>{{ $editing ? 'Edit Template' : 'Create Template' }}</h1><span>Compose a reusable Telegram message with optional media and inline buttons.</span></div><a href="{{ route('admin.telegram.index') }}"><i class="fa-solid fa-arrow-left"></i> Go back</a></div>
<form class="telegram-form-card" method="POST" enctype="multipart/form-data" action="{{ $editing ? route('admin.telegram.templates.update', $template) : route('admin.telegram.templates.store') }}" data-telegram-button-builder>
    @csrf @if($editing) @method('PUT') @endif
    <div class="telegram-form-section"><h2>Template Details</h2>
        <label>Name<input name="name" value="{{ old('name', $template->name) }}" placeholder="e.g. Chicken Road Promo" required></label>
        <label>Caption<textarea name="caption" rows="8" placeholder="e.g. &lt;b&gt;Big win alert!&lt;/b&gt;\nPlay now..." maxlength="1024" required>{{ old('caption', $template->caption) }}</textarea><small>Supports Telegram HTML such as &lt;b&gt; and &lt;i&gt;. Media captions are limited to 1,024 characters.</small></label>
        <div><span class="telegram-label">Image <small>(Optional)</small></span><label class="game-image-dropzone telegram-image-dropzone" data-image-dropzone>
            <input type="file" name="image" accept="image/png,image/jpeg,image/webp,image/gif" data-image-input>
            <img src="{{ $template->image_source ?? '' }}" alt="Template preview" data-image-preview @if(!$template->image_source) hidden @endif>
            <i class="fa-solid fa-arrow-up-from-bracket game-upload-icon"></i><strong data-upload-title>Drag & drop image here, or click to select</strong><small>PNG, JPG, WEBP or GIF up to 10MB</small>
        </label>@if($editing && $template->image_path)<label class="game-remove-image"><input type="checkbox" name="remove_image" value="1"> Remove existing image</label>@endif</div>
    </div>
    <div class="telegram-form-section telegram-buttons-section"><div class="telegram-section-title"><div><h2>Buttons</h2><p>Add URL or Telegram Web App buttons below the message.</p></div><button type="button" data-add-telegram-button><i class="fa-solid fa-plus"></i> Add Button</button></div>
        <div data-telegram-button-list>
            @foreach($buttons as $index => $button)
                @include('admin.telegram.partials.button-row', ['index' => $index, 'button' => $button])
            @endforeach
        </div>
        <p class="telegram-buttons-empty" data-telegram-buttons-empty @if(count($buttons)) hidden @endif>No buttons added yet. Click "Add Button" to create one.</p>
        <template data-telegram-button-template>@include('admin.telegram.partials.button-row', ['index' => '__INDEX__', 'button' => ['text'=>'','type'=>'url','value'=>'']])</template>
    </div>
    <footer><a href="{{ route('admin.telegram.index') }}">Cancel</a><button type="submit">{{ $editing ? 'Save Template' : 'Create Template' }}</button></footer>
</form>
@endsection
