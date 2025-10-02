<?php

declare(strict_types=1);

namespace App\Actions;

use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthCheckAction
{
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);

        $payload = [
            'data' => [
                'status' => 'ok',
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            ],
            'meta' => [
                'page' => 1,
                'size' => 1,
                'count' => 1,
                'total_items' => 1,
                'total_pages' => 1,
            ],
            'links' => [
                'self' => (string) $request->getUri(),
                'first' => (string) $request->getUri(),
                'prev' => null,
                'next' => null,
                'last' => (string) $request->getUri(),
            ],
            'trace_id' => $traceId,
            'source' => [
                'system' => 'api',
                'endpoint' => '/health',
            ],
        ];

        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Trace-Id', $traceId);
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
