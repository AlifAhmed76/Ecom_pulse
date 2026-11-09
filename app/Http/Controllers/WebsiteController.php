<?php

namespace App\Http\Controllers;

use App\Models\Website;
use App\Support\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WebsiteController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Website::class);

        $websites = Website::withCount('credentials')
            ->orderBy('name')
            ->paginate(20);

        return view('websites.index', compact('websites'));
    }

    public function create(): View
    {
        Gate::authorize('create', Website::class);

        return view('websites.create');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Website::class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'platform' => ['required', 'in:laravel,file'],
            'domain' => ['nullable', 'string', 'max:253'],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
        ]);

        $rawDomain = trim((string) ($data['domain'] ?? ''));
        $domain = null;

        if ($data['platform'] === 'laravel') {
            if ($rawDomain === '') {
                return back()
                    ->withErrors(['domain' => 'A store domain is required for a Laravel connector website.'])
                    ->withInput();
            }
            $domain = Domain::normalize($rawDomain);
            if ($domain === null) {
                return back()
                    ->withErrors(['domain' => 'Enter a valid domain such as shop.example.com.'])
                    ->withInput();
            }
        } elseif ($rawDomain !== '') {
            $domain = Domain::normalize($rawDomain);
            if ($domain === null) {
                return back()
                    ->withErrors(['domain' => 'Enter a valid domain or leave it blank for file-only imports.'])
                    ->withInput();
            }
        }

        if ($domain !== null && Website::withoutGlobalScopes()->where('domain', $domain)->exists()) {
            return back()
                ->withErrors(['domain' => 'This domain is already registered.'])
                ->withInput();
        }

        $website = new Website([
            'name' => trim($data['name']),
            'platform' => $data['platform'],
            'domain' => $domain,
            'currency' => strtoupper($data['currency'] ?? 'BDT'),
        ]);
        $website->forceFill([
            'tenant_id' => $request->user()->tenant_id,
            'status' => 'pending',
            'verification_token' => Str::random(40),
        ])->save();

        return redirect()
            ->route('websites.show', $website)
            ->with('ok', 'Website registered. You can now issue connector credentials or import files.');
    }

    public function show(Website $website): View
    {
        Gate::authorize('view', $website);

        $credentials = $website->credentials()->latest('id')->get();

        return view('websites.show', compact('website', 'credentials'));
    }

    public function destroy(Website $website): RedirectResponse
    {
        Gate::authorize('delete', $website);

        $website->delete();

        return redirect()
            ->route('websites.index')
            ->with('ok', 'Website removed.');
    }
}
