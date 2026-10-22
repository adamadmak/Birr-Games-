<?php

namespace App\Http\Controllers\Admin;

use App\Models\Promotion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PromotionController
{
    public function index(): View
    {
        return view('admin.promotions.index', [
            'promotions' => Promotion::query()->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($request->hasFile('image_upload') && ! $request->file('image_upload')->isValid()) {
            $error = $request->file('image_upload')->getError();
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded image exceeds the maximum allowed file size (5MB).',
                UPLOAD_ERR_PARTIAL => 'The image upload was interrupted. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary folder for upload.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write uploaded file to disk.',
                default => 'The image failed to upload. Please select a valid image file under 5MB.',
            };
            throw ValidationException::withMessages(['image_upload' => $message]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:500'],
            'promotion_type' => ['nullable', Rule::in(['spin_bonus', 'raffle', 'loyalty_bonus', 'tournament', 'crash_jackpot', 'other'])],
            'image_upload' => [$request->boolean('configuration_form') ? 'required' : 'nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
            'code' => ['nullable', 'string', 'max:40', 'unique:promotions,code'],
            'accent' => ['nullable', Rule::in(['cyan', 'gold', 'violet'])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);

        $type = $validated['promotion_type'] ?? 'other';
        $code = $validated['code'] ?? $this->uniqueCode($validated['title']);
        $imagePath = $request->hasFile('image_upload')
            ? $request->file('image_upload')->store('promotion-images', 'public')
            : null;

        Promotion::create([
            ...$validated,
            'code' => $code,
            'promotion_type' => $type,
            'image_path' => $imagePath,
            'accent' => $validated['accent'] ?? $this->accentFor($type),
            'active' => true,
        ]);

        return $request->boolean('configuration_form')
            ? redirect()->route('admin.settings.index', ['tab' => 'promotion'])->with('admin_status', 'Promotion created and published.')
            : back()->with('admin_status', 'Promotion created and published.');
    }

    public function toggle(Promotion $promotion): RedirectResponse
    {
        $promotion->update(['active' => !$promotion->active]);

        return back()->with('admin_status', 'Promotion status updated.');
    }

    public function destroy(Promotion $promotion): RedirectResponse
    {
        if ($promotion->image_path) {
            Storage::disk('public')->delete($promotion->image_path);
        }
        $promotion->delete();

        return back()->with('admin_status', 'Promotion deleted.');
    }

    private function uniqueCode(string $title): string
    {
        $base = Str::upper(Str::slug($title, '_')) ?: 'PROMOTION';
        $base = Str::limit($base, 32, '');
        $code = $base;
        $suffix = 2;

        while (Promotion::query()->where('code', $code)->exists()) {
            $code = Str::limit($base, 34, '').'_'.$suffix++;
        }

        return $code;
    }

    private function accentFor(string $type): string
    {
        return match ($type) {
            'raffle', 'tournament' => 'gold',
            'loyalty_bonus', 'crash_jackpot' => 'violet',
            default => 'cyan',
        };
    }
}
