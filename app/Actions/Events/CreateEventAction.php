<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\EventService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class CreateEventAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly EventService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/eventos",
     *     tags={"Eventos"},
     *     summary="Cria evento",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             required={"titulo","descricao","cidade","local","inicio","fim"},
     *             @OA\Property(property="titulo", type="string"),
     *             @OA\Property(property="descricao", type="string"),
     *             @OA\Property(property="cidade", type="string"),
     *             @OA\Property(property="local", type="string"),
     *             @OA\Property(property="inicio", type="string", format="date-time"),
     *             @OA\Property(property="fim", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=201, description="Evento criado"),
     *     @OA\Response(response=422, description="Dados invalidos")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $request->getParsedBody();

        if (!is_array($payload)) {
            $payload = [];
        }

        $usuarioId = $this->resolveUserId($request);

        try {
            $event = $this->service->create($payload, $usuarioId !== null ? (int) $usuarioId : null);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel criar o evento.');
        }

        return $this->responder->successResource(
            $response,
            $event,
            $traceId,
            ['source' => 'database']
        )->withHeader('X-Request-Id', $traceId)
         ->withStatus(201);
    }
}
