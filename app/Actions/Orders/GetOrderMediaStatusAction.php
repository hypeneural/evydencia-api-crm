<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\OrderMediaStatusService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use GuzzleHttp\Psr7\Utils;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetOrderMediaStatusAction
{
    use HandlesListAction;

    public const DEFAULT_SESSION_START = '2025-09-01';

    public function __construct(
        private readonly OrderMediaStatusService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/orders/media-status",
     *     tags={"Orders"},
     *     summary="Lista pedidos de Natal com status de midia",
     *     description="Consulta pedidos da campanha Natal no CRM, usando o intervalo pre-configurado (2025-09-01 ate a data atual) e verifica se existem pastas nas instancias da galeria e do game.",
     *     @OA\Response(
     *         response=200,
     *         description="Pedidos com status de midia",
     *         @OA\JsonContent(ref="#/components/schemas/OrderMediaStatusResponse")
     *     ),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha ao contatar CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        $sessionStart = self::DEFAULT_SESSION_START;
        $sessionEnd = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $productSlug = OrderMediaStatusService::TARGET_PRODUCT_SLUG;

        try {
            $result = $this->service->getMediaStatus($sessionStart, $sessionEnd, $traceId, $productSlug);
        } catch (CrmUnavailableException) {
            return $this->responder
                ->badGateway($response, $traceId, 'CRM timeout')
                ->withHeader('X-Request-Id', $traceId);
        } catch (CrmRequestException $exception) {
            return $this->responder
                ->badGateway(
                    $response,
                    $traceId,
                    sprintf('CRM error (status %d).', $exception->getStatusCode())
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('orders.media_status.unexpected', [
                'trace_id' => $traceId,
                'session_start' => $sessionStart,
                'session_end' => $sessionEnd,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Unexpected error while collecting media status.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $data = $result['data'];
        $summary = $result['summary'];
        $mediaStatus = $result['media_status'] ?? [];
        $count = count($data);
        $filters = isset($summary['filters']) && is_array($summary['filters']) ? $summary['filters'] : [];
        $summaryProductSlug = $filters['product_slug'] ?? null;
        $defaultProductSlug = $filters['default_product_slug'] ?? OrderMediaStatusService::TARGET_PRODUCT_SLUG;

        $payload = [
            'success' => true,
            'media_status' => $mediaStatus,
            'data' => $data,
            'summary' => $summary,
            'meta' => [
                'page' => 1,
                'per_page' => max(1, $count),
                'total' => $count,
                'elapsed_ms' => $elapsedMs,
                'filters' => [
                'session_start' => $sessionStart,
                'session_end' => $sessionEnd,
                'product_slug' => $summaryProductSlug,
                'default_product_slug' => $defaultProductSlug,
                'requested_product_slug' => $productSlug,
            ],
        ],
            'links' => [
                'self' => (string) $request->getUri(),
            ],
            'trace_id' => $traceId,
        ];

        $stream = Utils::streamFor(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Trace-Id', $traceId)
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('Cache-Control', 'no-store');
    }

    // Parameters are fixed; no additional helpers required.
}
