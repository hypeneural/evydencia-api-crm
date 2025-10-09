<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class BulkUpdateScheduledPostsAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Patch(
     *     path="/v1/scheduled-posts/bulk",
     *     tags={"ScheduledPosts"},
     *     summary="Atualiza múltiplos agendamentos de uma única vez",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(
     *                 property="ids",
     *                 type="array",
     *                 @OA\Items(type="integer", minimum=1)
     *             ),
     *             @OA\Property(
     *                 property="updates",
     *                 type="object",
     *                 description="Campos a atualizar (ex.: scheduled_datetime, caption)"
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Atualização em lote concluída", @OA\JsonContent(type="object")),
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
                'message' => 'Informe uma lista de IDs para atualização.',
            ]]);
        }

        if (!isset($payload['updates']) || !is_array($payload['updates']) || $payload['updates'] === []) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'updates',
                'message' => 'Informe os campos a serem atualizados.',
            ]]);
        }

        try {
            $result = $this->service->bulkUpdate($payload['ids'], $payload['updates']);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to bulk update scheduled posts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Nao foi possivel atualizar os agendamentos.'
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

