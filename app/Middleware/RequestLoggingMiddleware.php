<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $startedAt = microtime(true);
        $response = $handler->handle($request);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $traceId = $response->getHeaderLine('Trace-Id');
        if ($traceId === '') {
            $traceIdAttribute = $request->getAttribute('trace_id');
            $traceId = is_string($traceIdAttribute) && $traceIdAttribute !== ''
                ? $traceIdAttribute
                : bin2hex(random_bytes(8));
            $response = $response->withHeader('Trace-Id', $traceId);
        }

        $this->logger->info('HTTP request handled', [
            'trace_id' => $traceId,
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $elapsedMs,
        ]);

        return $response;
    }
}

