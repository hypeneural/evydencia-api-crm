<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class BulkDeleteScheduledPostsAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Delete(
     *     path="/v1/scheduled-posts/bulk",
     *     tags={"ScheduledPosts"},
     *     summary="Remove múltiplos agendamentos",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="ids",
     *                 type="array",
     *                 @OA\Items(type="integer", minimum=1),
     *                 description="Identificadores a serem removidos"
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Resultados da remoção", @OA\JsonContent(type="object")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        if (!isset($payload['ids']) || !is_array($payload['ids']) || $payload['ids'] === []) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'ids',
                'message' => 'Informe uma lista de IDs para remoção.',
            ]]);
        }

        try {
            $result = $this->service->bulkDelete($payload['ids']);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to bulk delete scheduled posts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Nao foi possivel remover os agendamentos.'
            );
        }

        $meta = ['source' => 'api'];
        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        return $this->responder->success(
            $response,
            $result,
            $meta,
            $links,
            $traceId
        )->withHeader('X-Request-Id', $traceId);
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        return $input;
    }
}

