<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureDevAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!app()->environment(['local', 'development', 'testing'])) {
            abort(403, 'Developer admin is disabled outside local environments.');
        }

        $allowedIps = array_values(array_filter(array_map(
            static fn($value) => trim((string) $value),
            explode(',', (string) env('DEV_ADMIN_ALLOWED_IPS', ''))
        )));

        if (count($allowedIps) > 0 && !in_array($request->ip(), $allowedIps, true)) {
            abort(403, 'Your IP is not allowed for developer admin.');
        }

        $user = Auth::guard('web')->user();
        if (!$user) {
            return redirect()->route('dev-admin.login');
        }

        if (!(bool) ($user->is_admin ?? false)) {
            abort(403, 'Admin account required.');
        }

        return $next($request);
    }
}
