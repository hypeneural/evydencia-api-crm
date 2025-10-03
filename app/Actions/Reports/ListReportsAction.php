<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Application\Services\ReportEngine;
use App\Application\Support\ApiResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Lista todos os relat?rios dispon?veis no motor.
 *
 * Exemplo:
 *   curl -H "X-API-Key: <key>" http://api.local/v1/reports
 */
final class ListReportsAction
{
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly ApiResponder $responder
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $reports = $this->engine->list();
        $count = count($reports);

        $meta = [
            'page' => 1,
            'per_page' => max(1, $count),
            'count' => $count,
            'total' => $count,
            'source' => 'engine',
        ];

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        return $this->responder->successList($response, $reports, $meta, $links, $traceId)
            ->withHeader('X-Request-Id', $traceId);
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
