<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInitialPasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        $allowedPaths = [
            'api/me',
            'api/logout',
            'api/auth/change-initial-credentials',
            'api/broadcasting/auth',
        ];

        foreach ($allowedPaths as $path) {
            if ($request->is($path)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Password change required before accessing this resource.',
            'code' => 'MUST_CHANGE_PASSWORD',
            'must_change_password' => true,
        ], 423);
    }
}

