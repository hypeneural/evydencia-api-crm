<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Infrastructure\Metrics\MetricsService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use Throwable;

final class MetricsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly MetricsService $metrics)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->metrics->isEnabled()) {
            return $handler->handle($request);
        }

        $start = microtime(true);
        $routePattern = $this->resolveRoutePattern($request);
        $clientLabel = $this->resolveClientLabel($request);
        $method = $request->getMethod();

        try {
            $response = $handler->handle($request);
            $status = $response->getStatusCode();
        } catch (Throwable $exception) {
            $status = 500;
            $duration = microtime(true) - $start;
            $this->metrics->observeHttpRequest($method, $routePattern, $status, $clientLabel, $duration);

            throw $exception;
        }

        $duration = microtime(true) - $start;
        $this->metrics->observeHttpRequest($method, $routePattern, $status, $clientLabel, $duration);

        return $response;
    }

    private function resolveRoutePattern(ServerRequestInterface $request): string
    {
        $route = null;
        try {
            $route = RouteContext::fromRequest($request)->getRoute();
        } catch (Throwable) {
            // ignore
        }

        $pattern = $route?->getPattern() ?? $request->getUri()->getPath();
        if ($pattern === '') {
            return 'unknown';
        }

        return $pattern;
    }

    private function resolveClientLabel(ServerRequestInterface $request): string
    {
        $header = $request->getHeaderLine('X-Client-Type');
        if ($header !== '') {
            return $header;
        }

        $query = $request->getQueryParams()['client'] ?? null;
        if (is_string($query) && $query !== '') {
            return $query;
        }

        return 'web';
    }
}
