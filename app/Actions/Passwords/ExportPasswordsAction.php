<?php

declare(strict_types=1);

namespace App\Actions\Passwords;

use App\Application\Services\PasswordService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use OpenApi\Annotations as OA;
use App\Domain\Exception\ValidationException;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ExportPasswordsAction
{
    public function __construct(
        private readonly PasswordService $service,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/passwords/export",
     *     tags={"Passwords"},
     *     summary="Exporta senhas em JSON, CSV ou XLSX",
     *     @OA\Parameter(name="format", in="query", description="Formato desejado (json, csv, xlsx)", @OA\Schema(type="string", enum={"json","csv","xlsx"})),
     *     @OA\Response(response=200, description="Arquivo gerado"),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $queryParams = $request->getQueryParams();

        $format = isset($queryParams['format']) ? strtolower(trim((string) $queryParams['format'])) : 'csv';

        try {
            $options = $this->queryMapper->mapPasswords($queryParams);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (Throwable $exception) {
            $this->logger->error('passwords.export.query_failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel processar os filtros.');
        }

        $filters = $options->crmQuery['filters'] ?? [];
        $search = isset($options->crmQuery['search']) && is_string($options->crmQuery['search'])
            ? $options->crmQuery['search']
            : null;

        try {
            $export = $this->service->export($filters, $search, $options->sort, $format);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (RuntimeException $exception) {
            $this->logger->error('passwords.export.failed', [
                'trace_id' => $traceId,
                'format' => $format,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel exportar as senhas.');
        }

        /** @var StreamInterface $stream */
        $stream = $export['stream'];
        $contentType = $export['content_type'];
        $filename = $export['filename'];

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

    protected function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}

