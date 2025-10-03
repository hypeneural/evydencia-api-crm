<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;

final class UpdateScheduledPostAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Put(
     *     path="/v1/scheduled-posts/{id}",
     *     tags={"ScheduledPosts"},
     *     summary="Atualiza um agendamento",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ScheduledPostUpdatePayload")),
     *     @OA\Response(response=200, description="Registro atualizado", @OA\JsonContent(ref="#/components/schemas/ScheduledPostResourceResponse")),
     *     @OA\Response(response=404, description="Registro não encontrado", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     *
     * @OA\Patch(
     *     path="/v1/scheduled-posts/{id}",
     *     tags={"ScheduledPosts"},
     *     summary="Atualiza parcialmente um agendamento",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ScheduledPostUpdatePayload")),
     *     @OA\Response(response=200, description="Registro atualizado", @OA\JsonContent(ref="#/components/schemas/ScheduledPostResourceResponse")),
     *     @OA\Response(response=404, description="Registro não encontrado", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
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

        $payload = $this->normalizePayload($request->getParsedBody());
        if ($payload === []) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'body',
                'message' => 'Informe ao menos um campo para atualizar.',
            ]]);
        }

        try {
            $resource = $this->service->update($id, $payload, $traceId);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to update scheduled post', [
                'trace_id' => $traceId,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel atualizar o agendamento.');
        }

        $response = $this->responder->successResource(
            $response,
            $resource,
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
