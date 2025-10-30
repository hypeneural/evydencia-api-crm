<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class ListSchoolObservationsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/escolas/{id}/observacoes",
     *     tags={"Escolas"},
     *     summary="Lista observacoes da escola",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Lista paginada"),
     *     @OA\Response(response=422, description="Parametros invalidos"),
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

        $query = $request->getQueryParams();
        $page = isset($query['page']) ? max(1, (int) $query['page']) : 1;
        $perPage = isset($query['per_page']) ? max(1, min(100, (int) $query['per_page'])) : 20;

        try {
            $result = $this->service->listObservations($schoolId, $page, $perPage);
        } catch (Throwable $exception) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar as observacoes.');
        }

        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'count' => count($result['items']),
            'total' => $result['total'],
            'total_pages' => $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 1,
            'source' => 'database',
        ];

        $links = $this->buildLinks($request, new \App\Application\DTO\QueryOptions([], $page, $perPage, false, [], []), $meta, []);

        return $this->responder->successList(
            $response,
            $result['items'],
            $meta,
            $links,
            $traceId
        )->withHeader('X-Request-Id', $traceId);
    }
}
