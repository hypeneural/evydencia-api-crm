<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\EventService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class UpdateEventAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly EventService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Patch(
     *     path="/v1/eventos/{id}",
     *     tags={"Eventos"},
     *     summary="Atualiza evento",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="titulo", type="string"),
     *             @OA\Property(property="descricao", type="string"),
     *             @OA\Property(property="cidade", type="string"),
     *             @OA\Property(property="local", type="string"),
     *             @OA\Property(property="inicio", type="string", format="date-time"),
     *             @OA\Property(property="fim", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Evento atualizado"),
     *     @OA\Response(response=404, description="Nao encontrado"),
     *     @OA\Response(response=422, description="Dados invalidos")
     * )
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $id = isset($args['id']) ? (int) $args['id'] : 0;

        if ($id <= 0) {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'id', 'message' => 'Identificador invalido.'],
            ]);
        }

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $payload = [];
        }

        $usuarioId = $this->resolveUserId($request);

        try {
            $event = $this->service->update($id, $payload, $usuarioId !== null ? (int) $usuarioId : null);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel atualizar o evento.');
        }

        return $this->responder->successResource(
            $response,
            $event,
            $traceId,
            ['source' => 'database']
        )->withHeader('X-Request-Id', $traceId);
    }
}
