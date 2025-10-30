<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FilterNeighborhoodsAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/filtros/bairros",
     *     tags={"Escolas"},
     *     summary="Retorna bairros filtrados",
     *     @OA\Parameter(name="cidade_id", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="include_totais", in="query", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="Lista de filtros"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $filters = $request->getQueryParams();
        $includeTotals = filter_var($filters['include_totais'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $data = $this->service->getFilterNeighborhoods($filters, $includeTotals);

        return $this->responder->successList(
            $response,
            $data,
            [
                'count' => count($data),
                'source' => 'database',
            ],
            [
                'self' => (string) $request->getUri(),
            ],
            $traceId
        );
    }
}
