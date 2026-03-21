<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        return User::with(['roles', 'departments'])->get();
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'roles' => 'nullable|array',
            'departments' => 'nullable|array',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        if (isset($validated['roles'])) {
            $user->syncRoles($validated['roles']);
        }

        if (isset($validated['departments'])) {
            $user->departments()->sync($validated['departments']);
        }

        return response()->json($user->load(['roles', 'departments']), 201);
    }

    public function show(User $user)
    {
        return $user->load(['roles', 'departments']);
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => 'string|max:255',
            'email' => 'string|email|max:255|unique:users,email,'.$user->id,
            'password' => 'nullable|string|min:8',
            'roles' => 'nullable|array',
            'departments' => 'nullable|array',
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
            $user->syncRoles($validated['roles']);
        }

        if (isset($validated['departments'])) {
            $user->departments()->sync($validated['departments']);
        }

        return response()->json($user->load(['roles', 'departments']));
    }

    public function destroy(User $user)
    {
        if ($user->hasRole('Admin') && User::role('Admin')->count() <= 1) {
            return response()->json(['message' => 'Cannot delete the last admin.'], 403);
        }

        $user->delete();

        return response()->json(null, 204);
    }
}
