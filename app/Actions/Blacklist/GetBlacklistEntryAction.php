<?php
declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
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

    /**
     * @OA\Get(
     *     path="/v1/blacklist/{id}",
     *     tags={"Blacklist"},
     *     summary="Consulta um contato bloqueado",
     *     @OA\Parameter(name="id", in="path", required=true, description="Identificador numérico do registro.", @OA\Schema(type="integer", minimum=1)),
     *     @OA\Response(
     *         response=200,
     *         description="Registro localizado",
     *         @OA\JsonContent(ref="#/components/schemas/BlacklistResourceResponse")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Registro não encontrado",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Identificador inválido",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     )
     * )
     */
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

        $resource = $this->service->get($id);

        if ($resource === null) {
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

