<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();
        $successful = $user && Hash::check($request->password, $user->password);

        LoginAttempt::create([
            'email' => strtolower(trim((string) $request->email)),
            'successful' => $successful && (bool) ($user->is_active ?? true),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        if (! $successful) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! (bool) ($user->is_active ?? true)) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact an administrator.'],
            ]);
        }

        $token = $user->createToken($request->device_name)->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->load(['departments', 'restaurants']),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'roles' => $user->getRoleNames(),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = $request->user();
        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');

        return response()->json([
            'user' => $user->load(['departments', 'restaurants']),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'roles' => $user->getRoleNames(),
        ]);
    }
}
