<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $normalizedEmail = strtolower(trim($credentials['email']));

        if (! Auth::attempt([
            'email' => $normalizedEmail,
            'password' => $credentials['password'],
        ], false)) {
            $matched = User::where('email', $normalizedEmail)->first(['tenant_id']);
            AuditLog::record(
                'auth.login_failed',
                'Failed sign-in attempt',
                $matched?->tenant_id,
                null
            );

            return back()
                ->withErrors(['email' => 'Invalid email or password.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        AuditLog::record('auth.login', 'Signed in to workspace', $user->tenant_id, $user->id);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user !== null) {
            AuditLog::record('auth.logout', 'Signed out of workspace', $user->tenant_id, $user->id);
        }

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
