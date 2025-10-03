<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CreateScheduledPostAction
{
    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/scheduled-posts",
     *     tags={"ScheduledPosts"},
     *     summary="Cria um novo agendamento",
     *     @OA\RequestBody(required=true, @OA\JsonContent(ref="#/components/schemas/ScheduledPostCreatePayload")),
     *     @OA\Response(
     *         response=201,
     *         description="Agendamento criado",
     *         @OA\Header(header="Location", description="URL do recurso criado.", @OA\Schema(type="string", format="uri")),
     *         @OA\JsonContent(ref="#/components/schemas/ScheduledPostResourceResponse")
     *     ),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        try {
            $resource = $this->service->create($payload, $traceId);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to create scheduled post', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel criar o agendamento.');
        }

        $location = (string) $request->getUri() . '/' . $resource['id'];

        return $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'api'],
            ['self' => $location]
        )
            ->withStatus(201)
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('Location', $location);
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

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
