<?php

namespace App\Http\Controllers;

use App\Services\Rnd\FindingTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'in:en,bn'],
            'bn_digits' => ['nullable', 'boolean'],
        ]);

        $locale = FindingTemplates::normalizeLocale($data['locale']);
        session(['locale' => $locale]);

        if ($request->has('bn_digits')) {
            session(['bn_digits' => $request->boolean('bn_digits')]);
        } elseif ($locale === 'en') {
            session(['bn_digits' => false]);
        }

        return back();
    }
}
