<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Actions\Concerns\ResolvesRequestContext;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\NotFoundException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetSchoolAction
{
    use ResolvesRequestContext;

    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/escolas/{id}",
     *     tags={"Escolas"},
     *     summary="Detalhes completos de uma escola",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Escola encontrada"),
     *     @OA\Response(response=404, description="Nao encontrado"),
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

        try {
            $resource = $this->service->get($id);
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->error('schools.get.failed', [
                'trace_id' => $traceId,
                'school_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel recuperar a escola solicitada.');
        }

        return $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'database']
        )->withHeader('X-Request-Id', $traceId);
    }
}
