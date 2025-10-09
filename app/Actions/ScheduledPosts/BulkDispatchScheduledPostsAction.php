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

final class BulkDispatchScheduledPostsAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/scheduled-posts/bulk/dispatch",
     *     tags={"ScheduledPosts"},
     *     summary="Força o disparo de posts específicos",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="ids",
     *                 type="array",
     *                 @OA\Items(type="integer", minimum=1)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Resultado do disparo em lote", @OA\JsonContent(type="object")),
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
                'message' => 'Informe uma lista de IDs para processamento.',
            ]]);
        }

        try {
            $result = $this->service->bulkDispatch($payload['ids'], $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to bulk dispatch scheduled posts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Nao foi possivel processar os agendamentos solicitados.'
            );
        }

        $meta = [
            'source' => 'api',
            'summary' => $result['summary'] ?? [],
        ];

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        return $this->responder->success(
            $response,
            [
                'summary' => $result['summary'] ?? [],
                'items' => $result['items'] ?? [],
                'errors' => $result['errors'] ?? [],
            ],
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

