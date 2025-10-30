<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class UpdateSchoolObservationAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Put(
     *     path="/v1/escolas/{id}/observacoes/{observacao_id}",
     *     tags={"Escolas"},
     *     summary="Atualiza uma observacao da escola",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="observacao_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="observacao", type="string", maxLength=1000)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Atualizado"),
     *     @OA\Response(response=422, description="Dados invalidos"),
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

        $payload = $request->getParsedBody();
        if (!is_array($payload)) {
            $payload = [];
        }

        $observacao = trim((string) ($payload['observacao'] ?? ''));
        if ($observacao === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'observacao', 'message' => 'Campo obrigatorio.'],
            ]);
        }

        try {
            $usuarioId = $this->resolveUserId($request);
            $resource = $this->service->updateObservation($observationId, $observacao, $usuarioId !== null ? (int) $usuarioId : null);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel atualizar a observacao.');
        }

        return $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'database']
        )->withHeader('X-Request-Id', $traceId);
    }
}
