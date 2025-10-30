<?php

declare(strict_types=1);

namespace App\Actions\Events;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\EventService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListEventLogsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly EventService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/eventos/{id}/logs",
     *     tags={"Eventos"},
     *     summary="Lista logs de um evento",
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="page", in="query", @OA\Schema(type="integer")),
     *     @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Lista paginada de logs")
     * )
     * @param array<string, string> $args
     */
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $eventId = isset($args['id']) ? (int) $args['id'] : 0;

        if ($eventId <= 0) {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'id', 'message' => 'Identificador invalido.'],
            ]);
        }

        $query = $request->getQueryParams();
        $page = isset($query['page']) ? (int) $query['page'] : 1;
        $perPage = isset($query['per_page']) ? (int) $query['per_page'] : 20;

        $result = $this->service->listLogs($eventId, $page, $perPage);

        $links = $this->buildLinks(
            $request,
            new \App\Application\DTO\QueryOptions([], $page, $perPage, false, [], []),
            $result['meta'],
            []
        );

        return $this->responder->successList(
            $response,
            $result['items'],
            $result['meta'],
            $links,
            $traceId
        )->withHeader('X-Request-Id', $traceId);
    }
}
