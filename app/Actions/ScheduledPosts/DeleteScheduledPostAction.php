<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;

final class DeleteScheduledPostAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Delete(
     *     path="/v1/scheduled-posts/{id}",
     *     tags={"ScheduledPosts"},
     *     summary="Remove um agendamento",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Operação concluída",
     *         @OA\JsonContent(
     *             allOf={
     *                 @OA\Schema(ref="#/components/schemas/SuccessEnvelope"),
     *                 @OA\Schema(@OA\Property(property="data", type="object", @OA\Property(property="deleted", type="boolean", example=true)))
     *             }
     *         )
     *     ),
     *     @OA\Response(response=404, description="Registro não encontrado", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=422, description="Identificador inválido", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $id = $this->resolveId($request);

        if ($id === null) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'id',
                'message' => 'Identificador invalido.',
            ]]);
        }

        try {
            $deleted = $this->service->delete($id);
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to delete scheduled post', [
                'trace_id' => $traceId,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel remover o agendamento.');
        }

        $response = $this->responder->successResource(
            $response,
            ['deleted' => $deleted],
            $traceId,
            ['source' => 'api'],
            ['self' => (string) $request->getUri()]
        );

        return $response->withHeader('X-Request-Id', $traceId);
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    private function resolveId(Request $request): ?int
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $id = $route?->getArgument('id');
        if (!is_string($id)) {
            return null;
        }

        $intId = (int) filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $intId > 0 ? $intId : null;
    }
}
