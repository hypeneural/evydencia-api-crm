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

final class CreateSchoolObservationAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/escolas/{id}/observacoes",
     *     tags={"Escolas"},
     *     summary="Cria uma observacao para a escola",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="observacao", type="string", maxLength=1000)
     *         )
     *     ),
     *     @OA\Response(response=201, description="Criado"),
     *     @OA\Response(response=422, description="Dados invalidos"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $schoolId = isset($args['id']) ? (int) $args['id'] : 0;
        if ($schoolId <= 0) {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'id', 'message' => 'Identificador invalido.'],
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

        $usuarioId = $this->resolveUserId($request);

        try {
            $resource = $this->service->createObservation($schoolId, $usuarioId !== null ? (int) $usuarioId : null, $observacao);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable $exception) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel criar a observacao.');
        }

        $apiResponse = $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'database']
        );

        return $apiResponse
            ->withHeader('X-Request-Id', $traceId)
            ->withStatus(201);
    }
}
