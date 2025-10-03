<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Application\Services\ReportEngine;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Executa um relat?rio a partir da chave declarada no motor.
 *
 * Exemplo:
 *   curl -H "X-API-Key: <key>" "http://api.local/v1/reports/orders.missing_schedule?product[slug]=natal&per_page=50"
 */
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
            $this->logger->error('report_engine.run.error', [
                'trace_id' => $traceId,
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Erro inesperado ao executar relatorio.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $meta = $result->meta;
        if ($result->summary !== []) {
            $meta['summary'] = $result->summary;
        }

        $links = [
            'self' => (string) $request->getUri(),
            'export' => (string) $request->getUri()->withPath($request->getUri()->getPath() . '/export'),
            'prev' => null,
        ];
        $links['next'] = null;

        return $this->responder->successList($response, $result->data, $meta, $links, $traceId)
            ->withHeader('X-Request-Id', $traceId);
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
