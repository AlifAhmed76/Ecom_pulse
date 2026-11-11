<?php

namespace App\Listeners;

use App\Models\Chatbot;
use App\Models\Website;
use App\Services\Security\SecurityEventService;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;

class RecordSecuritySignals
{
    public function __construct(private readonly SecurityEventService $security)
    {
    }

    public function handle(RequestHandled $event): void
    {
        $request = $event->request;
        $response = $event->response;

        if (
            $this->security->isSuspiciousProbePath($request->getRequestUri())
            && ! $request->attributes->get('_ly_suspicious_recorded')
        ) {
            [$tenantId, $websiteId] = $this->resolveAttribution($request);
            $this->security->record(
                'suspicious_request',
                'low',
                $tenantId,
                $websiteId,
                $request,
                [
                    'pattern_matched' => true,
                    'status' => $response->getStatusCode(),
                    'advisory' => 'Path heuristic only; /wp-admin, .php, or probe strings do not prove malicious intent.',
                ]
            );
        }

        if (
            $response->getStatusCode() === 429
            && ! $request->attributes->get('_ly_rate_limit_recorded')
        ) {
            [$tenantId, $websiteId] = $this->resolveAttribution($request);
            $this->security->record(
                'rate_limit_hit',
                'medium',
                $tenantId,
                $websiteId,
                $request,
                ['limiter' => 'route', 'status' => 429]
            );
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function resolveAttribution(Request $request): array
    {
        $user = $request->user();
        if ($user?->tenant_id !== null) {
            return [(int) $user->tenant_id, null];
        }

        $website = $request->attributes->get('connector_website');
        if ($website instanceof Website) {
            return [(int) $website->tenant_id, (int) $website->id];
        }

        $chatbot = $request->attributes->get('widget_chatbot');
        if ($chatbot instanceof Chatbot) {
            return [(int) $chatbot->tenant_id, (int) $chatbot->website_id];
        }

        return [null, null];
    }
}
