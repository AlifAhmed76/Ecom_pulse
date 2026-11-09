<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:100'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $tenant = Tenant::create([
                'name' => trim($validated['company_name']),
                'slug' => Tenant::makeUniqueSlug($validated['company_name']),
            ]);

            $user = new User([
                'name' => trim($validated['name']),
                'email' => strtolower(trim($validated['email'])),
                'password' => $validated['password'],
            ]);
            // Server-authoritative assignment: never accept tenant_id or role from request input.
            $user->forceFill([
                'tenant_id' => $tenant->id,
                'role' => User::ROLE_OWNER,
            ])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        AuditLog::record('auth.register', 'Registered tenant workspace', $user->tenant_id, $user->id);

        return redirect()->route('dashboard');
    }
}
