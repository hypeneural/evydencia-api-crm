<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class DeleteSchoolObservationAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Delete(
     *     path="/v1/escolas/{id}/observacoes/{observacao_id}",
     *     tags={"Escolas"},
     *     summary="Remove uma observacao da escola",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="observacao_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=204, description="Removido"),
     *     @OA\Response(response=422, description="Parametros invalidos"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $observationId = isset($args['observacao_id']) ? (int) $args['observacao_id'] : 0;
        if ($observationId <= 0) {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'observacao_id', 'message' => 'Identificador invalido.'],
            ]);
        }

        $usuarioId = $this->resolveUserId($request);
        $deleted = $this->service->deleteObservation($observationId, $usuarioId !== null ? (int) $usuarioId : null);

        if (!$deleted) {
            return $this->responder->notFound($response, $traceId, 'Observacao nao encontrada.');
        }

        return $response
            ->withStatus(204)
            ->withHeader('X-Request-Id', $traceId);
    }
}
