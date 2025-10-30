<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class FilterPeriodsAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/filtros/periodos",
     *     tags={"Escolas"},
     *     summary="Retorna periodos das escolas",
     *     @OA\Response(response=200, description="Lista de periodos"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $filters = $request->getQueryParams();
        $data = $this->service->getFilterPeriods($filters);

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
