<?php

namespace Kombee\IndexAdvisor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks the current request so the Smart Index Advisor query listener skips logging
 * (avoids feedback loops when the dashboard reads index_advisor_* tables).
 */
class PreventIndexAdvisorLogging
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('index_advisor_skip_logging', true);

        return $next($request);
    }
}
