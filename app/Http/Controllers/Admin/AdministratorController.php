<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Support\Username;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdministratorController
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->trim()->toString();
        $administrators = User::query()->where('role', User::ROLE_ADMIN)
            ->when($search, fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->latest()->paginate(15)->withQueryString();

        return view('admin.administrators.index', compact('administrators', 'search'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:160', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30', 'unique:users,phone'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            'admin_role' => ['required', Rule::in(array_keys(User::adminRoles()))],
        ]);

        $validated['admin_permissions'] = User::permissionsForRole($validated['admin_role']);
        // Administrators sign in with their email address; the handle is only for display.
        $validated['username'] = Username::unique(
            [Str::before($validated['email'], '@'), $validated['name']],
            $validated['phone'] ?? null,
        );
        User::create($validated + ['role' => User::ROLE_ADMIN, 'credits' => 0, 'active' => true]);
        return back()->with('admin_status', 'Administrator created.');
    }

    public function toggle(User $user): RedirectResponse
    {
        abort_unless($user->isAdmin(), 404);
        abort_if($user->is(auth()->user()), 422, 'You cannot disable your own administrator account.');
        $user->update(['active' => !$user->active]);
        return back()->with('admin_status', 'Administrator status updated.');
    }
}
