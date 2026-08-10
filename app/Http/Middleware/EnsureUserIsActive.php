<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && ! (bool) ($user->is_active ?? true)) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => 'Your account has been deactivated.'], 403);
        }

        return $next($request);
    }
}
