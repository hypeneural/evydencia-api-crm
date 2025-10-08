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

final class DispatchScheduledPostsAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/scheduled-posts/worker/dispatch",
     *     tags={"ScheduledPosts"},
     *     summary="Processa agendamentos pendentes e publica no status",
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="limit", type="integer", minimum=1, example=20, description="Quantidade máxima de posts processados na execução.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Execução concluída",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="summary",
     *                 type="object",
     *                 @OA\Property(property="limit", type="integer"),
     *                 @OA\Property(property="processed", type="integer"),
     *                 @OA\Property(property="sent", type="integer"),
     *                 @OA\Property(property="failed", type="integer"),
     *                 @OA\Property(property="skipped", type="integer")
     *             ),
     *             @OA\Property(
     *                 property="items",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="type", type="string"),
     *                     @OA\Property(property="scheduled_datetime", type="string"),
     *                     @OA\Property(property="status", type="string"),
     *                     @OA\Property(property="messageId", type="string", nullable=true),
     *                     @OA\Property(property="zaapId", type="string", nullable=true),
     *                     @OA\Property(property="provider_status", type="integer", nullable=true),
     *                     @OA\Property(property="error", type="string", nullable=true)
     *                 )
     *             )
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
        $payload = $this->normalizePayload($request->getParsedBody());

        $limit = isset($payload['limit']) ? (int) $payload['limit'] : null;
        if ($limit !== null && $limit <= 0) {
            $limit = null;
        }

        try {
            $result = $this->service->dispatchReady($limit, $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to dispatch scheduled posts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Nao foi possivel processar os agendamentos.'
            );
        }

        $meta = [
            'source' => 'api',
            'extra' => $result['summary'] ?? [],
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
