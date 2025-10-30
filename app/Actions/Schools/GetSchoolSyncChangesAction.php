<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

final class GetSchoolSyncChangesAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/sync/changes",
     *     tags={"Escolas"},
     *     summary="Recupera mudancas para sincronizacao offline",
     *     @OA\Parameter(name="since", in="query", @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="limit", in="query", @OA\Schema(type="integer", minimum=1, maximum=500)),
     *     @OA\Response(response=200, description="Mudancas obtidas"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $query = $request->getQueryParams();
        $since = isset($query['since']) ? (string) $query['since'] : '';
        $limit = isset($query['limit']) ? (int) $query['limit'] : 100;

        try {
            $data = $this->service->getSyncChanges($since, $limit);
        } catch (Throwable) {
            return $this->responder->internalError($response, $traceId, 'Nao foi possivel recuperar as mudancas.');
        }

        return $this->responder->success(
            $response,
            $data,
            [
                'source' => 'database',
                'filters' => [
                    'since' => $since,
                    'limit' => $limit,
                ],
            ],
            [
                'self' => (string) $request->getUri(),
            ],
            $traceId
        );
    }
}
