<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\ScheduledPostService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetScheduledPostAnalyticsAction
{
    use HandlesListAction;

    public function __construct(
        private readonly ScheduledPostService $service,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/scheduled-posts/analytics",
     *     tags={"ScheduledPosts"},
     *     summary="Retorna métricas e KPIs dos agendamentos",
     *     @OA\Parameter(name="filter[type]", in="query", required=false, @OA\Schema(type="string", enum={"text","image","video"})),
     *     @OA\Parameter(name="filter[status]", in="query", required=false, @OA\Schema(type="string", enum={"pending","scheduled","sent","failed"})),
     *     @OA\Parameter(name="filter[scheduled_datetime][gte]", in="query", required=false, @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="filter[scheduled_datetime][lte]", in="query", required=false, @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="filter[created_at][gte]", in="query", required=false, @OA\Schema(type="string", format="date-time")),
     *     @OA\Parameter(name="filter[created_at][lte]", in="query", required=false, @OA\Schema(type="string", format="date-time")),
     *     @OA\Response(
     *         response=200,
     *         description="Indicadores consolidados",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="summary", type="object"),
     *             @OA\Property(property="success_rate", type="number", format="float"),
     *             @OA\Property(property="by_type", type="object"),
     *             @OA\Property(property="by_date", type="array", @OA\Items(type="object")),
     *             @OA\Property(property="recent_activity", type="object"),
     *             @OA\Property(property="upcoming", type="object"),
     *             @OA\Property(property="performance", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);

        try {
            $options = $this->queryMapper->mapScheduledPosts($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->service->analytics($options);
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to compute scheduled post analytics', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Nao foi possivel carregar os indicadores.'
            );
        }

        $filtersApplied = $result['filters_applied'] ?? [];
        $availableFilters = $result['available_filters'] ?? [];
        unset($result['filters_applied'], $result['available_filters']);

        $meta = [
            'source' => 'api',
            'filters_applied' => $filtersApplied,
            'available_filters' => $availableFilters,
        ];

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        return $this->responder->success(
            $response,
            $result,
            $meta,
            $links,
            $traceId
        )->withHeader('X-Request-Id', $traceId);
    }
}

