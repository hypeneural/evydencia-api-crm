<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Application\Support\ApiResponder;
use App\Infrastructure\Cache\RedisRateLimiter;
use App\Settings\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use Slim\Routing\RouteContext;

final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RedisRateLimiter $rateLimiter,
        private readonly Settings $settings,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rateLimitSettings = $this->settings->getRateLimit();
        $limit = (int) ($rateLimitSettings['per_minute'] ?? 60);
        $window = (int) ($rateLimitSettings['window'] ?? 60);

        if (!$this->rateLimiter->isEnabled() || $limit <= 0) {
            return $handler->handle($request);
        }

        $traceId = $request->getAttribute('trace_id');
        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
            $request = $request->withAttribute('trace_id', $traceId);
        }

        $serverParams = $request->getServerParams();
        $ipAddress = $serverParams['HTTP_X_FORWARDED_FOR'] ?? $serverParams['REMOTE_ADDR'] ?? 'anonymous';
        if (is_string($ipAddress) && str_contains($ipAddress, ',')) {
            $ipAddress = trim(explode(',', $ipAddress)[0]);
        }

        $route = RouteContext::fromRequest($request)->getRoute();
        $endpoint = $route?->getPattern() ?? $request->getUri()->getPath();

        $key = sprintf('rate_limit:%s:%s', sha1((string) $ipAddress), sha1($endpoint));
        $result = $this->rateLimiter->hit($key, $limit, $window);

        if ($result['allowed']) {
            $response = $handler->handle($request)
                ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
                ->withHeader('X-RateLimit-Remaining', (string) $result['remaining']);

            if ($result['reset'] !== null) {
                $response = $response->withHeader('X-RateLimit-Reset', (string) $result['reset']);
            }

            return $response;
        }

        $this->logger->warning('Rate limit exceeded', [
            'trace_id' => $traceId,
            'ip' => $ipAddress,
            'endpoint' => $endpoint,
            'limit' => $limit,
            'window' => $window,
        ]);

        $baseResponse = new Response(429);
        $response = $this->responder->tooManyRequests($baseResponse, $traceId, 'Too Many Requests', $window)
            ->withHeader('X-RateLimit-Limit', (string) $result['limit'])
            ->withHeader('X-RateLimit-Remaining', '0');

        if ($result['reset'] !== null) {
            $response = $response->withHeader('X-RateLimit-Reset', (string) $result['reset']);
        }

        return $response;
    }
}

