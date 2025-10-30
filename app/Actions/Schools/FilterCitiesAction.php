<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FilterCitiesAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/filtros/cidades",
     *     tags={"Escolas"},
     *     summary="Retorna cidades com totais de escolas e pendencias",
     *     @OA\Response(response=200, description="Lista de filtros"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $filters = $request->getQueryParams();

        $data = $this->service->getFilterCities($filters);

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
