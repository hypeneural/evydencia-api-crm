<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Application\Services\ReportEngine;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListReportsAction
{
    public function __construct(private readonly ReportEngine $engine)
    {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $reports = $this->engine->list();
        $count = count($reports);

        $payload = [
            'success' => true,
            'data' => $reports,
            'summary' => ['count' => $count],
            'meta' => [
                'page' => 1,
                'per_page' => $count > 0 ? $count : 1,
                'total' => $count,
                'took_ms' => 0,
            ],
            'links' => [
                'self' => (string) $request->getUri(),
                'export_csv' => null,
                'export_json' => null,
            ],
            'trace_id' => $traceId,
        ];

        $stream = Utils::streamFor(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('Cache-Control', 'no-store');
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
