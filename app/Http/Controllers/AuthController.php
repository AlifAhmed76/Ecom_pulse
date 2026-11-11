<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Security\SecurityEventService;
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

            $security = app(SecurityEventService::class);
            $security->record(
                'login_failed',
                'medium',
                $matched?->tenant_id !== null ? (int) $matched->tenant_id : null,
                null,
                $request,
                ['reason' => 'invalid_credentials']
            );

            if ($matched?->tenant_id !== null && $security->shouldThrottle((int) $matched->tenant_id, 'login_failed', $request)) {
                $request->attributes->set('_ly_rate_limit_recorded', true);
                abort(429, 'Too many failed login attempts for this workspace rule.');
            }

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
