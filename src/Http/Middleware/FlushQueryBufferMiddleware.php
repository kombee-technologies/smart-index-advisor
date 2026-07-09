<?php

namespace Kombee\IndexAdvisor\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kombee\IndexAdvisor\Services\RuntimeQueryListener;
use Symfony\Component\HttpFoundation\Response;

/**
 * Terminable middleware that flushes the RuntimeQueryListener's in-memory
 * query buffer to the database after the response has been sent to the client.
 *
 * During the request, RuntimeQueryListener::handle() buffers captured queries
 * instead of writing them synchronously. This middleware's terminate() method
 * is called by Laravel after the response is sent, at which point all buffered
 * queries and explains are batch-written to the database.
 *
 * Register in the global middleware stack (or in the package service provider):
 *   $router->pushMiddlewareToGroup('web', FlushQueryBufferMiddleware::class);
 *   $router->pushMiddlewareToGroup('api', FlushQueryBufferMiddleware::class);
 */
class FlushQueryBufferMiddleware
{
    public function __construct(private RuntimeQueryListener $listener) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Called by Laravel after the response has been sent to the browser.
     * Flushes all buffered query and explain data to the database in a single
     * batch operation.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! config('index_advisor.enabled')) {
            return;
        }

        $this->listener->flush();
    }
}
