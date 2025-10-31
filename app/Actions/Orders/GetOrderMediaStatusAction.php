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
     *     description="Consulta pedidos da campanha Natal no CRM e verifica se existem pastas nas instancias da galeria e do game.",
     *     @OA\Parameter(
     *         name="session_start",
     *         in="query",
     *         required=false,
     *         description="Data inicial da sessao (YYYY-MM-DD). Default: 2025-09-01.",
     *         @OA\Schema(type="string", pattern="^\d{4}-\d{2}-\d{2}$")
     *     ),
     *     @OA\Parameter(
     *         name="session_end",
     *         in="query",
     *         required=false,
     *         description="Data final da sessao (YYYY-MM-DD). Default: ontem.",
     *         @OA\Schema(type="string", pattern="^\d{4}-\d{2}-\d{2}$")
     *     ),
     *     @OA\Parameter(
     *         name="product_slug",
     *         in="query",
     *         required=false,
     *         description="Slug do produto a ser filtrado. Default: natal. Use * para retornar todos.",
     *         @OA\Schema(type="string")
     *     ),
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

        $params = $request->getQueryParams();
        [$sessionStart, $sessionEnd, $errors] = $this->resolveDateRange($params);
        $productSlug = $this->resolveProductSlug($params);

        if ($errors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $errors)
                ->withHeader('X-Request-Id', $traceId);
        }

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

    /**
     * @param array<string, mixed> $params
     * @return array{0:string,1:string,2:array<int, array{field:string, message:string}>}
     */
    private function resolveDateRange(array $params): array
    {
        $errors = [];
        $sessionStart = isset($params['session_start']) && is_string($params['session_start'])
            ? trim($params['session_start'])
            : self::DEFAULT_SESSION_START;
        $sessionEnd = isset($params['session_end']) && is_string($params['session_end'])
            ? trim($params['session_end'])
            : (new \DateTimeImmutable('yesterday'))->format('Y-m-d');

        if (!$this->isValidIsoDate($sessionStart)) {
            $errors[] = ['field' => 'session_start', 'message' => 'Utilize o formato YYYY-MM-DD.'];
        }

        if (!$this->isValidIsoDate($sessionEnd)) {
            $errors[] = ['field' => 'session_end', 'message' => 'Utilize o formato YYYY-MM-DD.'];
        }

        if ($errors !== []) {
            return [$sessionStart, $sessionEnd, $errors];
        }

        $startDate = \DateTimeImmutable::createFromFormat('Y-m-d', $sessionStart);
        $endDate = \DateTimeImmutable::createFromFormat('Y-m-d', $sessionEnd);
        if ($startDate === false || $endDate === false) {
            $errors[] = ['field' => 'session_start', 'message' => 'Datas invalidas.'];

            return [$sessionStart, $sessionEnd, $errors];
        }

        if ($startDate > $endDate) {
            $errors[] = ['field' => 'session_start', 'message' => 'A data inicial nao pode ser posterior a data final.'];
        }

        $minimum = \DateTimeImmutable::createFromFormat('Y-m-d', self::DEFAULT_SESSION_START);
        if ($minimum !== false && $startDate < $minimum) {
            $errors[] = ['field' => 'session_start', 'message' => sprintf('Informe data igual ou posterior a %s.', self::DEFAULT_SESSION_START)];
        }

        $yesterday = new \DateTimeImmutable('yesterday');
        if ($endDate > $yesterday) {
            $errors[] = ['field' => 'session_end', 'message' => 'A data final deve ser no maximo ontem.'];
        }

        return [$sessionStart, $sessionEnd, $errors];
    }

    private function isValidIsoDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveProductSlug(array $params): ?string
    {
        $value = null;

        if (isset($params['product_slug']) && is_string($params['product_slug'])) {
            $value = $params['product_slug'];
        } elseif (isset($params['product']) && is_array($params['product']) && isset($params['product']['slug']) && is_string($params['product']['slug'])) {
            $value = $params['product']['slug'];
        }

        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
