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
            'success' => true,
            'data' => [
                'status' => 'ok',
                'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            ],
            'meta' => [
                'page' => 1,
                'per_page' => 1,
                'total' => 1,
                'source' => 'api',
            ],
            'links' => [
                'self' => (string) $request->getUri(),
                'next' => null,
                'prev' => null,
            ],
            'trace_id' => $traceId,
        ];

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $body->write((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
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

