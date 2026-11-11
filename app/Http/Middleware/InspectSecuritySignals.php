<?php

namespace App\Http\Middleware;

use App\Services\Security\SecurityEventService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InspectSecuritySignals
{
    public function __construct(private readonly SecurityEventService $security)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->security->isSuspiciousProbePath($request->getRequestUri())) {
            $user = $request->user();
            $tenantId = $user?->tenant_id !== null ? (int) $user->tenant_id : null;

            $request->attributes->set('_ly_suspicious_recorded', true);
            $this->security->record(
                'suspicious_request',
                'low',
                $tenantId,
                null,
                $request,
                [
                    'pattern_matched' => true,
                    'advisory' => 'Path heuristic only; /wp-admin, .php, or probe strings do not prove malicious intent.',
                ]
            );

            // Default rule mode is 'log_only'. Only throttles if tenant explicitly opted in.
            if ($tenantId !== null && $this->security->shouldThrottle($tenantId, 'suspicious_request', $request)) {
                $request->attributes->set('_ly_rate_limit_recorded', true);

                return response('Too many suspicious requests matching opt-in throttle rule.', 429);
            }
        }

        return $next($request);
    }
}
