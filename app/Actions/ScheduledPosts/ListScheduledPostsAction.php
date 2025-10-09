<?php



declare(strict_types=1);



namespace App\Actions\ScheduledPosts;



use App\Actions\Concerns\HandlesListAction;

use App\Application\DTO\QueryOptions;

use App\Application\Services\ScheduledPostService;

use App\Application\Support\ApiResponder;

use App\Application\Support\QueryMapper;

use OpenApi\Annotations as OA;

use App\Domain\Exception\ValidationException;

use App\Infrastructure\Cache\ScheduledPostCache;

use Psr\Http\Message\ResponseInterface as Response;

use Psr\Http\Message\ServerRequestInterface as Request;

use Psr\Log\LoggerInterface;

use RuntimeException;



final class ListScheduledPostsAction

{

    use HandlesListAction;



    public function __construct(

        private readonly ScheduledPostService $service,

        private readonly ScheduledPostCache $cache,

        private readonly QueryMapper $queryMapper,

        private readonly ApiResponder $responder,

        private readonly LoggerInterface $logger

    ) {

    }



    /**

     * @OA\Get(

     *     path="/v1/scheduled-posts",

     *     tags={"ScheduledPosts"},

     *     summary="Lista agendamentos de disparos",

     *     @OA\Parameter(name="page", in="query", required=false, description="Página atual (>=1)", @OA\Schema(type="integer", minimum=1, default=1)),

     *     @OA\Parameter(name="per_page", in="query", required=false, description="Itens por página (1-200)", @OA\Schema(type="integer", minimum=1, maximum=200, default=50)),

     *     @OA\Parameter(name="fetch", in="query", required=false, description="Use 'all' para retornar todos os itens de uma vez.", @OA\Schema(type="string", enum={"all"})),

     *     @OA\Parameter(name="q", in="query", required=false, description="Busca por mensagem/caption.", @OA\Schema(type="string")),

     *     @OA\Parameter(name="sort", in="query", required=false, description="Ordenação ex.: 'scheduled_datetime,-id'", @OA\Schema(type="string")),

     *     @OA\Parameter(name="fields", in="query", required=false, description="Campos desejados (ex.: fields=id,type).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="filter[type]", in="query", required=false, description="Filtra por tipo (text,image,video).", @OA\Schema(type="string", enum={"text","image","video"})),

     *     @OA\Parameter(name="filter[scheduled_datetime][gte]", in="query", required=false, description="Data inicial (YYYY-MM-DD HH:MM:SS).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="filter[scheduled_datetime][lte]", in="query", required=false, description="Data final (YYYY-MM-DD HH:MM:SS).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="filter[message_id_state]", in="query", required=false, description="Filtra por entregues (not_null) ou pendentes (null).", @OA\Schema(type="string", enum={"null","not_null"})),

     *     @OA\Parameter(name="If-None-Match", in="header", required=false, description="Cache condicional via ETag.", @OA\Schema(type="string")),

     *     @OA\Response(

     *         response=200,

     *         description="Lista paginada",

     *         @OA\Header(header="X-Total-Count", description="Total de registros.", @OA\Schema(type="integer")),

     *         @OA\Header(header="ETag", description="Hash da versão da lista.", @OA\Schema(type="string")),

     *         @OA\JsonContent(ref="#/components/schemas/ScheduledPostListResponse")

     *     ),

     *     @OA\Response(response=304, description="Conteúdo não modificado"),

     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),

     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))

     * )
     */

    public function __invoke(Request $request, Response $response): Response

    {

        $traceId = $this->resolveTraceId($request);

        $startedAt = microtime(true);



        try {

            $options = $this->queryMapper->mapScheduledPosts($request->getQueryParams());

        } catch (ValidationException $exception) {

            return $this->responder->validationError($response, $traceId, $exception->getErrors());

        }



        $signature = $this->buildSignature($options);

        $ifNoneMatch = trim($request->getHeaderLine('If-None-Match'));

        $cached = $this->cache->get($signature);

        if ($cached !== null) {

            $etag = $cached['etag'] ?? null;

            if ($etag !== null && $etag !== '' && $this->compareEtags($etag, $ifNoneMatch)) {

                return $response

                    ->withStatus(304)

                    ->withHeader('Trace-Id', $traceId)

                    ->withHeader('X-Request-Id', $traceId)

                    ->withHeader('ETag', $etag)

                    ->withHeader('Cache-Control', 'private, max-age=60');

            }

        }



        try {

            $result = $this->service->list($options, $traceId);

        } catch (RuntimeException $exception) {

            $this->logger->error('Failed to list scheduled posts', [

                'trace_id' => $traceId,

                'error' => $exception->getMessage(),

            ]);



            return $this->responder->internalError($response, $traceId, 'Nao foi possivel listar os agendamentos.');

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



        $etag = $this->generateEtag($result['max_updated_at'], $result['total'], $signature);

        if ($etag !== null) {

            $payload = [

                'data' => $result['data'],

                'meta' => $meta,

                'links' => $links,

                'total' => $result['total'],

            ];

            $this->cache->set($signature, $payload, $etag);

            $response = $response->withHeader('ETag', $etag)

                ->withHeader('Cache-Control', 'private, max-age=60');

        }



        return $response

            ->withHeader('X-Request-Id', $traceId)

            ->withHeader('X-Total-Count', (string) $result['total']);

    }



    /**

     * @return array<string, mixed>

     */

    private function buildSignature(QueryOptions $options): array

    {

        return [

            'filters' => $options->crmQuery['filters'] ?? [],

            'page' => $options->page,

            'per_page' => $options->perPage,

            'fetch_all' => $options->fetchAll,

            'sort' => $options->sort,

        ];

    }



    private function generateEtag(?string $maxUpdatedAt, int $total, array $signature): ?string

    {

        if ($maxUpdatedAt === null) {

            return null;

        }



        $payload = json_encode([

            'version' => $maxUpdatedAt,

            'total' => $total,

            'signature' => $signature,

        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);



        if ($payload === false) {

            return null;

        }



        return '"' . sha1($payload) . '"';

    }



    private function compareEtags(string $etag, string $header): bool

    {

        if ($header === '') {

            return false;

        }



        $normalizedHeader = trim($header, '"');

        $normalizedEtag = trim($etag, '"');



        return $normalizedHeader === $normalizedEtag;

    }

}

