<?php
declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ListBlacklistAction
{
    use HandlesListAction;

    public function __construct(
        private readonly BlacklistService $service,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/blacklist",
     *     tags={"Blacklist"},
     *     summary="Lista contatos bloqueados",
     *     description="Retorna a lista paginada de contatos bloqueados no CRM/WhatsApp, com suporte a filtros e cache condicional.",
     *     @OA\Parameter(name="page", in="query", description="Página atual (>=1)", required=false, @OA\Schema(type="integer", minimum=1, default=1)),
     *     @OA\Parameter(name="per_page", in="query", description="Tamanho da página (1-200)", required=false, @OA\Schema(type="integer", minimum=1, maximum=200, default=50)),
     *     @OA\Parameter(name="fetch", in="query", description="Use 'all' para retornar todos os registros em uma única página.", required=false, @OA\Schema(type="string", enum={"all"})),
     *     @OA\Parameter(name="q", in="query", description="Busca textual por nome ou WhatsApp.", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="sort", in="query", description="Ordenação ex.: '-created_at,name'.", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="fields", in="query", description="Campos opcionais (ex.: fields=id,name ou fields[blacklist]=id,name).", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[whatsapp]", in="query", description="Filtra por WhatsApp (apenas dígitos).", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[name]", in="query", description="Filtra por nome (exato ou parcial).", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="filter[has_closed_order]", in="query", description="Filtra por clientes com pedido fechado.", required=false, @OA\Schema(type="boolean")),
     *     @OA\Parameter(name="filter[created_at][gte]", in="query", description="Data inicial (YYYY-MM-DD).", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="filter[created_at][lte]", in="query", description="Data final (YYYY-MM-DD).", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="If-None-Match", in="header", description="Valor de ETag para cache condicional.", required=false, @OA\Schema(type="string")),
     *     @OA\Response(
     *         response=200,
     *         description="Lista paginada",
     *         @OA\Header(header="X-Total-Count", description="Total de registros encontrados.", @OA\Schema(type="integer")),
     *         @OA\Header(header="ETag", description="Hash da versão da lista.", @OA\Schema(type="string")),
     *         @OA\JsonContent(ref="#/components/schemas/BlacklistListResponse")
     *     ),
     *     @OA\Response(
     *         response=304,
     *         description="Conteúdo não modificado"
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Não autorizado",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Parâmetros inválidos",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Erro interno",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
     *     )
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $options = $this->queryMapper->mapBlacklist($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->service->list($options, $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to list blacklist entries', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar a blacklist.');
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $meta = $result['meta'];
        $meta['elapsed_ms'] = $elapsedMs;
        $links = $this->buildLinks($request, $options, $meta, []);

        $response = $this->responder->successList(
            $response,
            $result['data'],
            $meta,
            $links,
            $traceId
        );

        return $response
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Total-Count', (string) ($result['total'] ?? 0));
    }
}

