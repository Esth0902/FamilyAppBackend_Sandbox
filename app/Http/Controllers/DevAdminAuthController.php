<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DevAdminAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (!app()->environment(['local', 'development', 'testing'])) {
            abort(403, 'Developer admin is disabled outside local environments.');
        }

        $user = Auth::guard('web')->user();
        if ($user && (bool) ($user->is_admin ?? false)) {
            return redirect()->route('dev-admin.index');
        }

        return view('dev-admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        if (!app()->environment(['local', 'development', 'testing'])) {
            abort(403, 'Developer admin is disabled outside local environments.');
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::guard('web')->attempt($credentials, true)) {
            return back()
                ->withErrors(['email' => 'Identifiants invalides.'])
                ->withInput($request->only('email'));
        }

        $request->session()->regenerate();

        $user = Auth::guard('web')->user();
        if (!$user || !(bool) ($user->is_admin ?? false)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withErrors(['email' => 'Compte non autorisé pour le panel admin.'])
                ->withInput($request->only('email'));
        }

        return redirect()->route('dev-admin.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dev-admin.login');
    }
}
