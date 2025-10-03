<?php
declare(strict_types=1);

namespace App\Actions;

use DateTimeImmutable;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class HealthCheckAction
{
    /**
     * @OA\Get(
     *     path="/health",
     *     tags={"Health"},
     *     summary="Verifica disponibilidade da API",
     *     description="Retorna o status atual da API e um identificador de rastreamento.",
     *     security={},
     *     @OA\Response(
     *         response=200,
     *         description="Serviço operacional",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
     *                 @OA\Schema(
     *                     @OA\Property(
     *                         property="data",
     *                         type="object",
     *                         @OA\Property(property="status", type="string", example="ok"),
     *                         @OA\Property(property="timestamp", type="string", format="date-time", example="2025-10-03T18:20:00Z")
     *                     )
     *                 )
     *             ]
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erro interno",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     )
     * )
     */
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

