<?php



declare(strict_types=1);



namespace App\Actions\Orders;



use App\Actions\Concerns\HandlesListAction;

use App\Application\Services\OrderService;

use App\Application\Support\ApiResponder;

use App\Application\Support\QueryMapper;

use OpenApi\Annotations as OA;

use App\Domain\Exception\CrmRequestException;

use App\Domain\Exception\CrmUnavailableException;

use App\Domain\Exception\ValidationException;

use Psr\Http\Message\ResponseInterface as Response;

use Psr\Http\Message\ServerRequestInterface as Request;

use Psr\Log\LoggerInterface;

use RuntimeException;



final class SearchOrdersAction

{

    use HandlesListAction;



    public function __construct(

        private readonly OrderService $orderService,

        private readonly QueryMapper $queryMapper,

        private readonly ApiResponder $responder,

        private readonly LoggerInterface $logger

    ) {

    }



    /**

     * @OA\Get(

     *     path="/v1/orders/search",

     *     tags={"Orders"},

     *     summary="Pesquisa pedidos no CRM",

     *     description="Consulta a API do CRM com filtros, paginação e ordenação, retornando os pedidos enriquecidos com dados locais.",

     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", minimum=1, default=1)),

     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=200, default=50)),

     *     @OA\Parameter(name="fetch", in="query", required=false, description="Use 'all' para buscar várias páginas do CRM.", @OA\Schema(type="string", enum={"all"})),

     *     @OA\Parameter(name="sort", in="query", required=false, description="Ordenação (ex.: '-created_at,status').", @OA\Schema(type="string")),

     *     @OA\Parameter(name="fields[orders]", in="query", required=false, description="Projeção de campos (ex.: uuid,status,total).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="q", in="query", required=false, description="Termo livre para busca textual.", @OA\Schema(type="string")),

     *     @OA\Parameter(name="order[status]", in="query", required=false, description="Filtra por status ex.: paid, canceled.", @OA\Schema(type="string")),

     *     @OA\Parameter(name="order[created-start]", in="query", required=false, description="Data inicial (YYYY-MM-DD).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="order[created-end]", in="query", required=false, description="Data final (YYYY-MM-DD).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="customer[email]", in="query", required=false, description="E-mail do cliente.", @OA\Schema(type="string", format="email")),

     *     @OA\Parameter(name="customer[whatsapp]", in="query", required=false, description="WhatsApp do cliente (apenas dígitos).", @OA\Schema(type="string")),

     *     @OA\Parameter(name="product[uuid]", in="query", required=false, description="Produto vinculado.", @OA\Schema(type="string")),

     *     @OA\Response(

     *         response=200,

     *         description="Pedidos encontrados",

     *         @OA\JsonContent(ref="#/components/schemas/GenericListResponse")

     *     ),

     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),

     *     @OA\Response(response=502, description="Erro ao contatar CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),

     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))

     * )
     */

    public function __invoke(Request $request, Response $response): Response

    {

        $traceId = $this->resolveTraceId($request);

        $startedAt = microtime(true);



        try {

            $options = $this->queryMapper->mapOrdersSearch($request->getQueryParams());

        } catch (ValidationException $exception) {

            return $this->responder->validationError($response, $traceId, $exception->getErrors());

        }



        try {

            $result = $this->orderService->searchOrders($options, $traceId);

        } catch (CrmUnavailableException) {

            return $this->responder->badGateway($response, $traceId, 'CRM timeout');

        } catch (CrmRequestException $exception) {

            return $this->responder->badGateway(

                $response,

                $traceId,

                sprintf('CRM error (status %d).', $exception->getStatusCode())

            );

        } catch (RuntimeException $exception) {

            $this->logger->error('Unexpected error while searching orders', [

                'trace_id' => $traceId,

                'error' => $exception->getMessage(),

            ]);



            return $this->responder->internalError($response, $traceId, 'Unexpected error while searching orders.');

        }



        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $meta = $result['meta'];

        $meta['elapsed_ms'] = $elapsedMs;

        $links = $this->buildLinks($request, $options, $meta, $result['crm_links'] ?? []);



        return $this->responder->successList(

            $response,

            $result['data'],

            $meta,

            $links,

            $traceId

        );

    }

}



