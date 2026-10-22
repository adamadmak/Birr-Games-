<article class="telegram-button-row" data-telegram-button-row>
    <div class="telegram-button-row-title"><strong>Button <span data-telegram-button-number>{{ is_numeric($index) ? $index + 1 : '' }}</span></strong><button type="button" data-remove-telegram-button aria-label="Remove button"><i class="fa-regular fa-trash-can"></i></button></div>
    <label>Text<input name="buttons[{{ $index }}][text]" value="{{ $button['text'] ?? '' }}" placeholder="e.g. Play now" required></label>
    <label>Type<select name="buttons[{{ $index }}][type]" required><option value="url" @selected(($button['type'] ?? '') === 'url')>URL</option><option value="web_app" @selected(($button['type'] ?? '') === 'web_app')>Web App</option></select></label>
    <label>Value<input type="url" name="buttons[{{ $index }}][value]" value="{{ $button['value'] ?? '' }}" placeholder="https://..." required></label>
</article>
