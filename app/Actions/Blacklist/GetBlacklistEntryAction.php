<?php

declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Routing\RouteContext;

final class GetBlacklistEntryAction
{
    public function __construct(
        private readonly BlacklistService $service,
        private readonly ApiResponder $responder
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $id = $this->resolveId($request);

        if ($id === null) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'id',
                'message' => 'Identificador invalido.',
            ]]);
        }
\n        $resource = $this->service->get($id);\n\n        if ($resource === null) {
            return $this->responder->notFound($response, $traceId);
        }

        $resource['has_closed_order'] = (bool) ($resource['has_closed_order'] ?? false);

        $response = $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'api'],
            ['self' => (string) $request->getUri()]
        );

        return $response->withHeader('X-Request-Id', $traceId);
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    private function resolveId(Request $request): ?int
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $id = $route?->getArgument('id');
        if (!is_string($id)) {
            return null;
        }

        $intId = (int) filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $intId > 0 ? $intId : null;
    }
}
