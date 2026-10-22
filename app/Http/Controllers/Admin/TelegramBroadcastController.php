<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\SendTelegramBroadcastBatch;
use App\Jobs\SyncTelegramBroadcastDeleteBatch;
use App\Jobs\SyncTelegramBroadcastEditBatch;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastDelivery;
use App\Models\TelegramSubscriber;
use App\Models\TelegramTemplate;
use App\Services\TelegramBotService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TelegramBroadcastController
{
    public function index(Request $request, TelegramBotService $telegram): View
    {
        $tab = in_array($request->string('tab')->toString(), ['templates', 'broadcasts'], true)
            ? $request->string('tab')->toString()
            : 'templates';
        $perPage = in_array($request->integer('per_page'), [10, 25, 50], true) ? $request->integer('per_page') : 10;

        return view('admin.telegram.index', [
            'tab' => $tab,
            'perPage' => $perPage,
            'templates' => TelegramTemplate::query()->latest()->paginate($perPage, ['*'], 'template_page')->withQueryString(),
            'broadcasts' => TelegramBroadcast::query()->with('template')->latest()->paginate($perPage, ['*'], 'broadcast_page')->withQueryString(),
            'activeSubscribers' => TelegramSubscriber::query()->where('active', true)->count(),
            'totalSubscribers' => TelegramSubscriber::query()->count(),
            'botConfigured' => $telegram->configured(),
            'botConfigurationSources' => $telegram->configurationSources(),
            'botUsername' => $telegram->botUsername(),
        ]);
    }

    public function configureConnection(Request $request, TelegramBotService $telegram): RedirectResponse
    {
        $validated = $request->validate([
            'bot_token' => ['required', 'string', 'max:255'],
        ]);

        try {
            $telegram->configureToken($validated['bot_token']);
            $url = route('telegram.webhook');
            if (!str_starts_with($url, 'https://')) {
                throw new \RuntimeException('Telegram webhooks require a public HTTPS application URL.');
            }
            $telegram->setWebhook($url);
        } catch (\Throwable $exception) {
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }

        return back()->with('admin_status', 'Telegram bot verified, encrypted, and connected.');
    }

    public function createTemplate(): View
    {
        return view('admin.telegram.template-form', ['template' => new TelegramTemplate]);
    }

    public function storeTemplate(Request $request): RedirectResponse
    {
        $validated = $this->validateTemplate($request);
        $validated['buttons'] = $this->normalizeButtons($validated['buttons'] ?? []);
        $validated['image_path'] = $request->hasFile('image')
            ? $request->file('image')->store('telegram-templates', 'public')
            : null;
        unset($validated['image']);

        TelegramTemplate::create($validated);

        return redirect()->route('admin.telegram.index')->with('admin_status', 'Telegram template created.');
    }

    public function editTemplate(TelegramTemplate $template): View
    {
        return view('admin.telegram.template-form', compact('template'));
    }

    public function updateTemplate(Request $request, TelegramTemplate $template): RedirectResponse
    {
        $validated = $this->validateTemplate($request);
        $validated['buttons'] = $this->normalizeButtons($validated['buttons'] ?? []);

        if ($request->hasFile('image')) {
            if ($template->image_path) {
                Storage::disk('public')->delete($template->image_path);
            }
            $validated['image_path'] = $request->file('image')->store('telegram-templates', 'public');
        } elseif ($request->boolean('remove_image') && $template->image_path) {
            Storage::disk('public')->delete($template->image_path);
            $validated['image_path'] = null;
        }
        unset($validated['image']);

        $template->update($validated);

        return redirect()->route('admin.telegram.index')->with('admin_status', 'Telegram template updated.');
    }

    public function destroyTemplate(TelegramTemplate $template): RedirectResponse
    {
        if ($template->image_path) {
            Storage::disk('public')->delete($template->image_path);
        }
        $template->delete();

        return back()->with('admin_status', 'Telegram template deleted.');
    }

    public function createBroadcast(): View
    {
        return view('admin.telegram.broadcast-form', [
            'templates' => TelegramTemplate::query()->latest()->get(),
            'activeSubscribers' => TelegramSubscriber::query()->where('active', true)->count(),
        ]);
    }

    public function storeBroadcast(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'telegram_template_id' => ['required', 'integer', 'exists:telegram_templates,id'],
            'scheduled' => ['nullable', 'boolean'],
            'scheduled_at' => [Rule::requiredIf($request->boolean('scheduled')), 'nullable', 'date', 'after:now'],
        ]);
        $template = TelegramTemplate::findOrFail($validated['telegram_template_id']);
        $scheduled = $request->boolean('scheduled');
        $broadcast = TelegramBroadcast::create([
            'telegram_template_id' => $template->id,
            'created_by_user_id' => $request->user()->id,
            'name' => $validated['name'],
            'caption' => $template->caption,
            'image_path' => $this->snapshotImage($template->image_path),
            'buttons' => $template->buttons,
            'status' => $scheduled ? 'scheduled' : 'draft',
            'scheduled_at' => $scheduled ? $validated['scheduled_at'] : null,
        ]);

        if ($scheduled) {
            if (!$this->prepareDeliveries($broadcast)) {
                $broadcast->update(['status' => 'draft', 'scheduled_at' => null]);
                return redirect()->route('admin.telegram.index', ['tab' => 'broadcasts'])
                    ->withErrors(['broadcast' => 'No active Telegram subscribers are available yet.']);
            }
            $this->dispatchBroadcast($broadcast, $broadcast->scheduled_at);
        }

        return redirect()->route('admin.telegram.index', ['tab' => 'broadcasts'])
            ->with('admin_status', $scheduled ? 'Broadcast scheduled.' : 'Broadcast draft created.');
    }

    public function sendBroadcast(TelegramBroadcast $broadcast, TelegramBotService $telegram): RedirectResponse
    {
        if (!$telegram->configured()) {
            return back()->withErrors(['telegram' => 'Configure TELEGRAM_BOT_TOKEN before sending.']);
        }

        if (!in_array($broadcast->status, ['draft', 'scheduled', 'queued', 'sending'], true)) {
            return back()->withErrors(['broadcast' => 'This broadcast has already finished and cannot be sent again.']);
        }

        if (!$this->prepareDeliveries($broadcast)) {
            return back()->withErrors(['broadcast' => 'No active Telegram subscribers are available yet.']);
        }

        $broadcast->update(['status' => 'queued', 'scheduled_at' => null]);
        (new SendTelegramBroadcastBatch($broadcast->id, drain: true))->handle($telegram);
        $broadcast->refresh();

        if ($broadcast->sent_count === 0 && $broadcast->failed_count > 0) {
            return back()->withErrors([
                'broadcast' => "Broadcast finished, but all {$broadcast->failed_count} deliveries failed. Open Detail to review the result.",
            ]);
        }

        return back()->with('admin_status', sprintf(
            'Broadcast delivered: %d sent, %d failed.',
            $broadcast->sent_count,
            $broadcast->failed_count,
        ));
    }

    public function retryBroadcast(TelegramBroadcast $broadcast, TelegramBotService $telegram): RedirectResponse
    {
        if (!$telegram->configured()) {
            return back()->withErrors(['telegram' => 'Configure TELEGRAM_BOT_TOKEN before retrying.']);
        }

        $failed = $broadcast->deliveries()->where('status', 'failed')->count();
        if ($failed === 0) {
            return back()->withErrors(['broadcast' => 'This broadcast has no failed deliveries to retry.']);
        }

        $broadcast->deliveries()->where('status', 'failed')->update([
            'status' => 'pending', 'error' => null, 'sent_at' => null, 'updated_at' => now(),
        ]);
        $broadcast->update([
            'status' => 'queued', 'failed_count' => 0, 'completed_at' => null,
        ]);

        (new SendTelegramBroadcastBatch($broadcast->id, drain: true))->handle($telegram);
        $broadcast->refresh();

        return back()->with('admin_status', sprintf(
            'Retry finished: %d sent in total, %d still failed.',
            $broadcast->sent_count,
            $broadcast->failed_count,
        ));
    }

    public function editBroadcast(TelegramBroadcast $broadcast): View
    {
        return view('admin.telegram.broadcast-form', [
            'broadcast' => $broadcast,
            'templates' => TelegramTemplate::query()->latest()->get(),
            'activeSubscribers' => TelegramSubscriber::query()->where('active', true)->count(),
        ]);
    }

    public function updateBroadcast(Request $request, TelegramBroadcast $broadcast, TelegramBotService $telegram): RedirectResponse
    {
        $this->ensureValidUpload($request, 'image', 10);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'caption' => ['required', 'string', 'max:1024'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'remove_image' => ['nullable', 'boolean'],
            'buttons' => ['nullable', 'array', 'max:8'],
            'buttons.*.text' => ['required', 'string', 'max:64'],
            'buttons.*.type' => ['required', Rule::in(['url', 'web_app'])],
            'buttons.*.value' => ['required', 'url:http,https', 'max:2048'],
            'scheduled' => ['nullable', 'boolean'],
            'scheduled_at' => [Rule::requiredIf($request->boolean('scheduled')), 'nullable', 'date', 'after:now'],
        ]);

        foreach ($validated['buttons'] ?? [] as $index => $button) {
            if ($button['type'] === 'web_app' && !str_starts_with(strtolower($button['value']), 'https://')) {
                throw ValidationException::withMessages([
                    "buttons.$index.value" => 'Telegram Web App buttons require an HTTPS URL.',
                ]);
            }
        }

        $updateData = [
            'name' => $validated['name'],
            'caption' => $validated['caption'],
            'buttons' => $this->normalizeButtons($validated['buttons'] ?? []),
        ];

        if ($request->hasFile('image')) {
            if ($broadcast->image_path) {
                Storage::disk('public')->delete($broadcast->image_path);
            }
            $updateData['image_path'] = $request->file('image')->store('telegram-broadcasts', 'public');
        } elseif ($request->boolean('remove_image') && $broadcast->image_path) {
            Storage::disk('public')->delete($broadcast->image_path);
            $updateData['image_path'] = null;
        }

        if (in_array($broadcast->status, ['draft', 'scheduled'], true)) {
            $scheduled = $request->boolean('scheduled');
            $updateData['scheduled_at'] = $scheduled ? $validated['scheduled_at'] : null;
            $updateData['status'] = $scheduled ? 'scheduled' : 'draft';
        }

        $broadcast->update($updateData);

        $sentDeliveriesCount = $broadcast->deliveries()->where('status', 'sent')->count();

        if ($sentDeliveriesCount > 0) {
            if (!$telegram->configured()) {
                return redirect()->route('admin.telegram.index', ['tab' => 'broadcasts'])
                    ->withErrors(['telegram' => 'Broadcast updated in database, but TELEGRAM_BOT_TOKEN is not configured to sync edits to Telegram subscribers.']);
            }

            (new SyncTelegramBroadcastEditBatch($broadcast->id, drain: true))->handle($telegram);

            return redirect()->route('admin.telegram.index', ['tab' => 'broadcasts'])
                ->with('admin_status', sprintf('Broadcast updated and edited on Telegram (%d message(s) updated).', $sentDeliveriesCount));
        }

        return redirect()->route('admin.telegram.index', ['tab' => 'broadcasts'])
            ->with('admin_status', 'Broadcast updated.');
    }

    public function destroyBroadcast(TelegramBroadcast $broadcast, TelegramBotService $telegram): RedirectResponse
    {
        $sentCount = $broadcast->deliveries()->where('status', 'sent')->whereNotNull('telegram_message_id')->count();

        if ($sentCount > 0 && $telegram->configured()) {
            (new SyncTelegramBroadcastDeleteBatch($broadcast->id, drain: true))->handle($telegram);
        } else {
            if ($broadcast->image_path) {
                Storage::disk('public')->delete($broadcast->image_path);
            }
            $broadcast->deliveries()->delete();
            $broadcast->delete();
        }

        return redirect()->route('admin.telegram.index', ['tab' => 'broadcasts'])
            ->with('admin_status', $sentCount > 0 ? 'Broadcast deleted and retracted from Telegram subscribers.' : 'Broadcast deleted.');
    }

    public function connectWebhook(TelegramBotService $telegram): RedirectResponse
    {
        try {
            $url = route('telegram.webhook');
            if (!str_starts_with($url, 'https://')) {
                throw new \RuntimeException('Telegram webhooks require a public HTTPS APP_URL. Configure it after deployment.');
            }
            $telegram->setWebhook($url);
        } catch (\Throwable $exception) {
            return back()->withErrors(['telegram' => $exception->getMessage()]);
        }

        return back()->with('admin_status', 'Telegram webhook connected. Users can now subscribe with /start.');
    }

    private function validateTemplate(Request $request): array
    {
        $this->ensureValidUpload($request, 'image', 10);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'caption' => ['required', 'string', 'max:1024'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
            'buttons' => ['nullable', 'array', 'max:8'],
            'buttons.*.text' => ['required', 'string', 'max:64'],
            'buttons.*.type' => ['required', Rule::in(['url', 'web_app'])],
            'buttons.*.value' => ['required', 'url:http,https', 'max:2048'],
        ]);

        foreach ($validated['buttons'] ?? [] as $index => $button) {
            if ($button['type'] === 'web_app' && !str_starts_with(strtolower($button['value']), 'https://')) {
                throw ValidationException::withMessages([
                    "buttons.$index.value" => 'Telegram Web App buttons require an HTTPS URL.',
                ]);
            }
        }

        return $validated;
    }

    private function ensureValidUpload(Request $request, string $field = 'image', int $maxMb = 10): void
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

    private function normalizeButtons(array $buttons): array
    {
        return collect($buttons)->map(fn (array $button) => [
            'text' => trim($button['text']),
            'type' => $button['type'],
            'value' => trim($button['value']),
        ])->values()->all();
    }

    private function snapshotImage(?string $source): ?string
    {
        if (!$source || !Storage::disk('public')->exists($source)) {
            return null;
        }

        $target = 'telegram-broadcasts/'.Str::uuid().'.'.pathinfo($source, PATHINFO_EXTENSION);
        Storage::disk('public')->copy($source, $target);

        return $target;
    }

    private function prepareDeliveries(TelegramBroadcast $broadcast): bool
    {
        if ($broadcast->deliveries()->exists()) {
            return $broadcast->deliveries()->where('status', 'pending')->exists();
        }

        $now = now();
        TelegramSubscriber::query()->where('active', true)->select(['id', 'chat_id'])->chunkById(500, function ($subscribers) use ($broadcast, $now): void {
            TelegramBroadcastDelivery::insert($subscribers->map(fn ($subscriber) => [
                'telegram_broadcast_id' => $broadcast->id,
                'telegram_subscriber_id' => $subscriber->id,
                'chat_id' => $subscriber->chat_id,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });
        $count = $broadcast->deliveries()->count();
        $broadcast->update(['total_recipients' => $count]);

        return $count > 0;
    }

    private function dispatchBroadcast(TelegramBroadcast $broadcast, mixed $delay = null): void
    {
        $pending = SendTelegramBroadcastBatch::dispatch($broadcast->id)
            ->onConnection((string) config('services.telegram.queue_connection', 'database'));
        if ($delay) {
            $pending->delay($delay);
        }
    }
}
