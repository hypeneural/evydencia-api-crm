<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Application\Services\ReportEngine;
use App\Application\Reports\ReportResult;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

final class RunReportAction
{
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    /**
     * @OA\Get(
     *     path="/v1/reports/{key}",
     *     tags={"Reports"},
     *     summary="Executa um relatório",
     *     @OA\Parameter(name="key", in="path", required=true, description="Identificador do relatório.", @OA\Schema(type="string")),
     *     @OA\Parameter(name="page", in="query", required=false, @OA\Schema(type="integer", minimum=1, default=1)),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", minimum=1, maximum=500, default=50)),
     *     @OA\Parameter(name="sort", in="query", required=false, description="Ordenação dinâmica (ex.: '-created_at').", @OA\Schema(type="string")),
     *     @OA\Parameter(name="_cache_enabled", in="query", required=false, description="Define se o cache deve ser usado (true/false).", @OA\Schema(type="boolean")),
     *     @OA\Response(response=200, description="Execução bem-sucedida", @OA\JsonContent(ref="#/components/schemas/ReportRunPayload")),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro no CRM ou fonte externa", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $key = isset($args['key']) ? trim((string) $args['key']) : '';

        if ($key === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'key', 'message' => 'Chave do relatorio obrigatoria.'],
            ])->withHeader('X-Request-Id', $traceId);
        }

        $query = $request->getQueryParams();

        try {
            $result = $this->engine->run($key, $query, $traceId);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors())
                ->withHeader('X-Request-Id', $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM indisponivel')
                ->withHeader('X-Request-Id', $traceId);
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('Erro ao consultar CRM (status %d).', $exception->getStatusCode())
            )->withHeader('X-Request-Id', $traceId);
        } catch (\Throwable $exception) {
            $this->logger->error('report.run.error', [
                'trace_id' => $traceId,
                'report_key' => $key,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Erro inesperado ao executar relatorio.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $payload = $this->buildPayload($result, $request, $traceId, $key);
        $stream = Utils::streamFor(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cacheMeta = $result->meta['cache'] ?? [];
        $cacheHeader = ($cacheMeta['hit'] ?? false) ? 'HIT' : 'MISS';

        return $response
            ->withBody($stream)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Cache', sprintf('%s; key=%s', $cacheHeader, $cacheMeta['key'] ?? ''))
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(ReportResult $result, Request $request, string $traceId, string $key): array
    {
        $meta = $result->meta;
        $summary = $result->summary;
        $links = [
            'self' => (string) $request->getUri(),
            'export_csv' => (string) $request->getUri()->withPath($request->getUri()->getPath() . '/export')->withQuery('format=csv&' . $request->getUri()->getQuery()),
            'export_json' => (string) $request->getUri()->withPath($request->getUri()->getPath() . '/export')->withQuery('format=json&' . $request->getUri()->getQuery()),
        ];

        return [
            'success' => true,
            'data' => $result->data,
            'summary' => $summary,
            'meta' => $meta,
            'columns' => $result->columns,
            'links' => $links,
            'trace_id' => $traceId,
        ];
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
