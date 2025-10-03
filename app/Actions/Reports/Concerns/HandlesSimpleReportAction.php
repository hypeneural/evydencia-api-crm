<?php

declare(strict_types=1);

namespace App\Actions\Reports\Concerns;

use Psr\Http\Message\ServerRequestInterface as Request;

trait HandlesSimpleReportAction
{
    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    /**
     * @param mixed $data
     * @return array<int, mixed>
     */
    private function normalizeList(mixed $data): array
    {
        if (!is_array($data)) {
            return $data === null ? [] : [$data];
        }

        if ($data === []) {
            return [];
        }

        return array_keys($data) === range(0, count($data) - 1) ? $data : [$data];
    }

    /**
     * @param array<string, mixed> $crmLinks
     * @return array<string, mixed>
     */
    private function buildLinks(Request $request, array $crmLinks): array
    {
        return [
            'self' => (string) $request->getUri(),
            'next' => $crmLinks['next'] ?? null,
            'prev' => $crmLinks['prev'] ?? null,
        ];
    }
}

