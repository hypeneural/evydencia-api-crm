<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use App\Actions\Concerns\HandlesListAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetReadyScheduledPostsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly ApiResponder $responder
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $limit = $this->resolveLimit($request->getQueryParams());

        $items = $this->service->getReady($limit);
        $count = count($items);

        $meta = [
            'page' => 1,
            'per_page' => $limit,
            'count' => $count,
            'total' => $count,
            'source' => 'api',
        ];

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        $response = $this->responder->successList(
            $response,
            $items,
            $meta,
            $links,
            $traceId
        );

        return $response->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Total-Count', (string) $count);
    }

    private function resolveLimit(array $queryParams): int
    {
        $limit = isset($queryParams['limit']) ? (int) $queryParams['limit'] : 50;
        if ($limit <= 0) {
            $limit = 50;
        }

        return $limit;
    }
}
