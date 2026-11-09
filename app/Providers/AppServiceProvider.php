<?php

namespace App\Providers;

use App\Models\Website;
use App\Policies\WebsitePolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Website::class, WebsitePolicy::class);

        RateLimiter::for('connector', function (Request $request): array {
            $tooManyResponse = fn (Request $req, array $headers) => response()->json([
                'ok' => false,
                'error' => 'rate_limited',
            ], 429, $headers);

            $limits = [
                Limit::perMinute(300)
                    ->by('connector-ip:'.$request->ip())
                    ->response($tooManyResponse),
            ];

            $key = (string) $request->header('X-LYP-Key', '');
            if ($key !== '') {
                $limits[] = Limit::perMinute(120)
                    ->by('connector-key:'.$key)
                    ->response($tooManyResponse);
            }

            return $limits;
        });
    }
}
