<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\EventService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteEventAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly EventService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Delete(
     *     path="/v1/eventos/{id}",
     *     tags={"Eventos"},
     *     summary="Remove evento",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Removido"),
     *     @OA\Response(response=404, description="Nao encontrado")
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

        $usuarioId = $this->resolveUserId($request);

        $deleted = $this->service->delete($id, $usuarioId !== null ? (int) $usuarioId : null);
        if (!$deleted) {
            return $this->responder->notFound($response, $traceId, 'Evento nao encontrado.');
        }

        return $response
            ->withStatus(204)
            ->withHeader('X-Request-Id', $traceId);
    }
}
