<?php

namespace App\Http\Controllers;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class AuthController extends Controller
{
    private const LOGIN_MAX_PER_ACCOUNT = 5;

    private const LOGIN_ACCOUNT_DECAY_SECONDS = 300;

    private const LOGIN_MAX_PER_IP = 30;

    private const LOGIN_IP_DECAY_SECONDS = 60;

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
            'device_name' => 'required',
        ]);

        $email = strtolower(trim((string) $request->email));
        $limits = [
            'login:'.$email.'|'.$request->ip() => [self::LOGIN_MAX_PER_ACCOUNT, self::LOGIN_ACCOUNT_DECAY_SECONDS],
            'login-ip:'.$request->ip() => [self::LOGIN_MAX_PER_IP, self::LOGIN_IP_DECAY_SECONDS],
        ];
        foreach ($limits as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => "Too many login attempts. Try again in {$seconds} seconds.",
                ], 429)->header('Retry-After', (string) $seconds);
            }
        }

        $user = User::where('email', $request->email)->first();
        $successful = $user && Hash::check($request->password, $user->password);

        LoginAttempt::create([
            'email' => $email,
            'successful' => $successful && (bool) ($user->is_active ?? true),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        if (! $successful) {
            foreach ($limits as $key => [, $decay]) {
                RateLimiter::hit($key, $decay);
            }

            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! (bool) ($user->is_active ?? true)) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated. Contact an administrator.'],
            ]);
        }

        RateLimiter::clear('login:'.$email.'|'.$request->ip());

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
