<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Throwable;

final class UpdateSchoolAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Patch(
     *     path="/v1/escolas/{id}",
     *     tags={"Escolas"},
     *     summary="Atualiza dados da escola",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="panfletagem", type="boolean"),
     *             @OA\Property(property="panfletagem_observacao", type="string"),
     *             @OA\Property(property="obs", type="string"),
     *             @OA\Property(property="periodos", type="array", @OA\Items(type="string")),
     *             @OA\Property(property="indicadores", type="object"),
     *             @OA\Property(property="etapas", type="object"),
     *             @OA\Property(property="total_alunos", type="integer"),
     *             @OA\Property(property="versao_row", type="integer")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Atualizado com sucesso"),
     *     @OA\Response(response=404, description="Nao encontrado"),
     *     @OA\Response(response=409, description="Conflito de versao"),
     *     @OA\Response(response=422, description="Dados invalidos"),
     *     @OA\Response(response=500, description="Erro interno")
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

        $userId = $this->resolveUserId($request);

        try {
            $resource = $this->service->update($id, $payload, $userId !== null ? (int) $userId : null);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (ConflictException $exception) {
            return $this->responder->conflict($response, $traceId, $exception->getMessage());
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->logger->error('schools.update.failed', [
                'trace_id' => $traceId,
                'school_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel atualizar a escola.');
        }

        return $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'database']
        )->withHeader('X-Request-Id', $traceId);
    }
}
