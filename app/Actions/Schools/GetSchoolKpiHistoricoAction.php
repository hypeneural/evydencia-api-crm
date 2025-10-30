<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class GetSchoolKpiHistoricoAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/kpis/historico",
     *     tags={"Escolas"},
     *     summary="Historico de panfletagem por periodo",
     *     @OA\Parameter(name="cidade_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="periodo", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Serie temporal"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $filters = $request->getQueryParams();

        try {
            $data = $this->service->getKpiHistorico($filters);
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel recuperar o historico.');
        }

        return $this->responder->successList(
            $response,
            $data,
            [
                'count' => count($data),
                'source' => 'database',
                'filters' => $filters,
            ],
            [
                'self' => (string) $request->getUri(),
            ],
            $traceId
        );
    }
}
