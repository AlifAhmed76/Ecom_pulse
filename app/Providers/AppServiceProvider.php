<?php

namespace App\Providers;

use App\Listeners\RecordSecuritySignals;
use App\Models\ApiCredential;
use App\Models\Website;
use App\Policies\WebsitePolicy;
use App\Services\Chat\WidgetTokenService;
use App\Services\Security\SecurityEventService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        Event::listen(RequestHandled::class, RecordSecuritySignals::class);

        RateLimiter::for('connector', function (Request $request): array {
            $tooManyResponse = function (Request $req, array $headers) {
                $tenantId = null;
                $websiteId = null;
                $keyId = (string) $req->header('X-LYP-Key', '');
                if ($keyId !== '') {
                    $cred = ApiCredential::query()
                        ->with(['website' => fn ($q) => $q->withoutGlobalScopes()])
                        ->where('key_id', $keyId)
                        ->first();
                    $tenantId = $cred?->website?->tenant_id !== null ? (int) $cred->website->tenant_id : null;
                    $websiteId = $cred?->website?->id !== null ? (int) $cred->website->id : null;
                }

                $req->attributes->set('_ly_rate_limit_recorded', true);
                app(SecurityEventService::class)->record(
                    'rate_limit_hit',
                    'medium',
                    $tenantId,
                    $websiteId,
                    $req,
                    ['limiter' => 'connector']
                );

                return response()->json([
                    'ok' => false,
                    'error' => 'rate_limited',
                ], 429, $headers);
            };

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

        RateLimiter::for('widget', function (Request $request): Limit {
            return Limit::perMinute(30)
                ->by('widget-ip:'.$request->ip())
                ->response(function (Request $req, array $headers) {
                    $ctx = app(WidgetTokenService::class)->verifiedSignatureContext($req);
                    $req->attributes->set('_ly_rate_limit_recorded', true);
                    app(SecurityEventService::class)->record(
                        'rate_limit_hit',
                        'medium',
                        $ctx['tenant_id'] ?? null,
                        $ctx['website_id'] ?? null,
                        $req,
                        ['limiter' => 'widget']
                    );

                    return response()->json([
                        'ok' => false,
                        'error' => 'rate_limited',
                    ], 429, $headers);
                });
        });

        RateLimiter::for('widget-order-lookup', function (Request $request): Limit {
            return Limit::perMinute(10)
                ->by('widget-order-ip:'.$request->ip())
                ->response(function (Request $req, array $headers) {
                    $ctx = app(WidgetTokenService::class)->verifiedSignatureContext($req);
                    $req->attributes->set('_ly_rate_limit_recorded', true);
                    app(SecurityEventService::class)->record(
                        'rate_limit_hit',
                        'medium',
                        $ctx['tenant_id'] ?? null,
                        $ctx['website_id'] ?? null,
                        $req,
                        ['limiter' => 'widget_order_lookup']
                    );

                    return response()->json([
                        'ok' => false,
                        'error' => 'rate_limited',
                    ], 429, $headers);
                });
        });
    }
}
