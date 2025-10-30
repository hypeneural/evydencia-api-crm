<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class GetSchoolKpiOverviewAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/kpis/overview",
     *     tags={"Escolas"},
     *     summary="KPIs agregados das escolas",
     *     @OA\Parameter(name="cidade_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="bairro_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="periodo", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="KPIs calculados"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $filters = $request->getQueryParams();

        try {
            $data = $this->service->getKpiOverview($filters);
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel calcular os KPIs.');
        }

        return $this->responder->success(
            $response,
            $data,
            [
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
