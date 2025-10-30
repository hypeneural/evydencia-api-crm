<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\EventService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListEventsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly EventService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/eventos",
     *     tags={"Eventos"},
     *     summary="Lista eventos",
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="cidade", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="Lista paginada de eventos")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $query = $request->getQueryParams();

        $page = isset($query['page']) ? (int) $query['page'] : 1;
        $perPage = isset($query['per_page']) ? (int) $query['per_page'] : 20;
        $filters = [
            'cidade' => $query['cidade'] ?? null,
            'search' => $query['search'] ?? null,
        ];

        $result = $this->service->list($filters, $page, $perPage);

        $links = $this->buildLinks(
            $request,
            new \App\Application\DTO\QueryOptions([], $result['meta']['page'], $result['meta']['per_page'], false, [], []),
            $result['meta'],
            []
        );

        return $this->responder->successList(
            $response,
            $result['data'],
            $result['meta'],
            $links,
            $traceId
        )->withHeader('X-Request-Id', $traceId)
         ->withHeader('X-Total-Count', (string) $result['meta']['total']);
    }
}
