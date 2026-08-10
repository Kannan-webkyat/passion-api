<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    private function checkPermission(string $permission)
    {
        $user = auth()->user();
        if ($user && ! $user->hasRole('Admin') && ! $user->can($permission)) {
            abort(403, 'Unauthorized action.');
        }
    }

    public function index()
    {
        return User::with(['roles', 'departments', 'restaurants'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function store(Request $request)
    {
        $this->checkPermission('manage-users');
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'roles' => 'nullable|array',
            'departments' => 'nullable|array',
            'restaurant_ids' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (isset($validated['roles'])) {
            if (in_array('Admin', $validated['roles']) && ! auth()->user()->hasRole('Admin')) {
                abort(403, 'Only Admins can assign the Admin role.');
            }
            $user->syncRoles($validated['roles']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (isset($validated['departments'])) {
            $user->departments()->sync($validated['departments']);
        }

        if (isset($validated['restaurant_ids'])) {
            $user->restaurants()->sync($validated['restaurant_ids']);
        }

        return response()->json($user->load(['roles', 'departments', 'restaurants']), 201);
    }

    public function show(User $user)
    {
        return $user->load(['roles', 'departments', 'restaurants']);
    }

    public function update(Request $request, User $user)
    {
        $this->checkPermission('manage-users');
        $validated = $request->validate([
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8',
            'roles' => 'nullable|array',
            'departments' => 'nullable|array',
            'restaurant_ids' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        if (array_key_exists('is_active', $validated) && $validated['is_active'] === false) {
            return $this->deactivateUser($user);
        }

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        if (isset($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if (array_key_exists('is_active', $validated) && $validated['is_active'] === true) {
            $user->is_active = true;
        }

        $user->save();

        if (isset($validated['roles'])) {
            if (in_array('Admin', $validated['roles']) && ! auth()->user()->hasRole('Admin')) {
                abort(403, 'Only Admins can assign the Admin role.');
            }
            // Also prevent removing Admin role from an admin if requester is not admin
            if ($user->hasRole('Admin') && ! auth()->user()->hasRole('Admin')) {
                abort(403, 'Cannot modify Admin users.');
            }
            $user->syncRoles($validated['roles']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (isset($validated['departments'])) {
            $user->departments()->sync($validated['departments']);
        }

        if (isset($validated['restaurant_ids'])) {
            $user->restaurants()->sync($validated['restaurant_ids']);
        }

        return response()->json($user->load(['roles', 'departments', 'restaurants']));
    }

    public function destroy(User $user)
    {
        return $this->deactivateUser($user);
    }

    private function deactivateUser(User $user)
    {
        $this->checkPermission('manage-users');

        if ((int) $user->id === (int) auth()->id()) {
            return response()->json(['message' => 'You cannot deactivate your own account.'], 422);
        }

        if ($user->hasRole('Admin') && ! auth()->user()->hasRole('Admin')) {
            abort(403, 'Only Admins can deactivate other Admins.');
        }

        if ($user->hasRole('Admin') && (bool) $user->is_active) {
            $otherActiveAdmins = User::role('Admin')
                ->where('users.is_active', true)
                ->where('users.id', '!=', $user->id)
                ->count();
            if ($otherActiveAdmins < 1) {
                return response()->json(['message' => 'Cannot deactivate the last active admin.'], 422);
            }
        }

        $user->is_active = false;
        $user->save();
        $user->tokens()->delete();

        return response()->json($user->load(['roles', 'departments', 'restaurants']));
    }
}
