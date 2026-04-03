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
        return User::with(['roles', 'departments', 'restaurants'])->get();
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
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
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
        ]);

        if (isset($validated['name'])) {
            $user->name = $validated['name'];
        }
        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        if (isset($validated['password'])) {
            $user->password = Hash::make($validated['password']);
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
        $this->checkPermission('manage-users');
        if ($user->hasRole('Admin') && ! auth()->user()->hasRole('Admin')) {
            abort(403, 'Only Admins can delete other Admins.');
        }

        if ($user->hasRole('Admin') && User::role('Admin')->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin.'], 403);
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
