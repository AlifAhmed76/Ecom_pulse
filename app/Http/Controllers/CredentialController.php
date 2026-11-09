<?php

namespace App\Http\Controllers;

use App\Models\ApiCredential;
use App\Models\Website;
use App\Services\CredentialService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class CredentialController extends Controller
{
    public function store(Request $request, Website $website): Response
    {
        Gate::authorize('manage', $website);

        $data = $request->validate([
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', array_keys(ApiCredential::SCOPES))],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        $expiresAt = isset($data['expires_in_days'])
            ? now()->addDays((int) $data['expires_in_days'])
            : null;

        $issued = CredentialService::issue(
            $website,
            $data['scopes'],
            $expiresAt,
            $request->user()->id
        );

        return $this->secretResponse($website, $issued['credential'], $issued['secret'], 'issued');
    }

    public function rotate(Request $request, Website $website, ApiCredential $credential): Response|RedirectResponse
    {
        Gate::authorize('manage', $website);

        if ((int) $credential->website_id !== (int) $website->id) {
            abort(404);
        }

        if (! $credential->isUsable()) {
            return redirect()
                ->route('websites.show', $website)
                ->with('err', 'Revoked or expired credentials cannot be rotated.');
        }

        $issued = CredentialService::rotate($credential, $request->user()->id);

        return $this->secretResponse($website, $issued['credential'], $issued['secret'], 'rotated');
    }

    public function revoke(Website $website, ApiCredential $credential): RedirectResponse
    {
        Gate::authorize('manage', $website);

        if ((int) $credential->website_id !== (int) $website->id) {
            abort(404);
        }

        CredentialService::revoke($credential);

        return redirect()
            ->route('websites.show', $website)
            ->with('ok', 'Credential revoked immediately.');
    }

    private function secretResponse(Website $website, ApiCredential $credential, string $secret, string $action): Response
    {
        return response()
            ->view('websites.secret', [
                'website' => $website,
                'credential' => $credential,
                'secret' => $secret,
                'action' => $action,
            ])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->header('Pragma', 'no-cache');
    }
}
