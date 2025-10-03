<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Application\Services\ReportEngine;
use OpenApi\Annotations as OA;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;

/**
 * Exporta relat?rios em CSV ou JSON.
 */
final class ExportReportAction
{
    public function __construct(
        private readonly ReportEngine $engine,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    /**
     * @OA\Post(
     *     path="/v1/reports/{key}/export",
     *     tags={"Reports"},
     *     summary="Exporta um relatório em CSV ou JSON",
     *     @OA\Parameter(name="key", in="path", required=true, description="Identificador do relatório.", @OA\Schema(type="string")),
     *     @OA\Parameter(name="format", in="query", required=false, description="Formato desejado (csv ou json).", @OA\Schema(type="string", enum={"csv","json"}, default="csv")),
     *     @OA\Response(
     *         response=200,
     *         description="Arquivo gerado",
     *         @OA\MediaType(mediaType="text/csv", @OA\Schema(type="string", format="binary")),
     *         @OA\MediaType(mediaType="application/json", @OA\Schema(type="string", format="binary"))
     *     ),
     *     @OA\Response(response=422, description="Parâmetros inválidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro no CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $traceId = $this->resolveTraceId($request);
        $key = isset($args['key']) ? trim((string) $args['key']) : '';
        if ($key === '') {
            return $this->jsonError($response, 422, $traceId, 'Chave do relatorio obrigatoria.');
        }

        $query = $request->getQueryParams();
        $format = strtolower((string) ($query['format'] ?? 'csv'));

        try {
            $stream = $this->engine->export($key, $query, $format, $traceId);
        } catch (ValidationException $exception) {
            return $this->jsonError($response, 422, $traceId, 'Parametros invalidos', $exception->getErrors());
        } catch (CrmUnavailableException) {
            return $this->jsonError($response, 502, $traceId, 'CRM indisponivel');
        } catch (CrmRequestException $exception) {
            return $this->jsonError(
                $response,
                502,
                $traceId,
                sprintf('Erro ao consultar CRM (status %d).', $exception->getStatusCode())
            );
        } catch (\Throwable $exception) {
            $this->logger->error('report_engine.export.error', [
                'trace_id' => $traceId,
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            return $this->jsonError($response, 500, $traceId, 'Erro inesperado ao exportar relatorio.');
        }

        $filename = sprintf('%s-%s.%s', str_replace(['.', '/'], '-', $key), gmdate('YmdHis'), $format);
        $contentType = $format === 'csv'
            ? 'text/csv; charset=utf-8'
            : 'application/json; charset=utf-8';

        $response = $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Request-Id', $traceId);

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        while (!$stream->eof()) {
            $body->write($stream->read(8192));
        }

        return $response;
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    private function jsonError(Response $response, int $status, string $traceId, string $message, array $errors = []): Response
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $status === 422 ? 'unprocessable_entity' : ($status >= 500 ? 'internal_error' : 'bad_gateway'),
                'message' => $message,
            ],
            'trace_id' => $traceId,
        ];

        if ($errors !== []) {
            $payload['error']['errors'] = $errors;
        }

        $stream = $this->createJsonStream($payload);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('X-Request-Id', $traceId)
            ->withBody($stream);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createJsonStream(array $payload): StreamInterface
    {
        return Utils::streamFor(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
