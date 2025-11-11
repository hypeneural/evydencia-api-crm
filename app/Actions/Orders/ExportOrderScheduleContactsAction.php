<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\OrderScheduleExportService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use DateTimeImmutable;
use Exception;
use GuzzleHttp\Psr7\Utils;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ExportOrderScheduleContactsAction
{
    use HandlesListAction;

    private const DEFAULT_SCHEDULE_START = '2025-11-15 11:00:00';

    public function __construct(
        private readonly OrderScheduleExportService $exportService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/orders/schedule/contacts",
     *     tags={"Orders"},
     *     summary="Exporta contatos unicos por agenda",
     *     description="Retorna texto no formato whatsapp;primeiro_nome (uma linha por cliente) para pedidos com schedule_1 entre a data inicial informada e o momento da requisicao. Remove duplicados por WhatsApp e ignora pedidos com status igual a 1.",
     *     @OA\Parameter(
     *         name="schedule_start",
     *         in="query",
     *         required=false,
     *         description="Data/hora inicial (ex.: 2025-11-15 11:00:00). Se ausente, usa o valor padrao do projeto.",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="schedule_end",
     *         in="query",
     *         required=false,
     *         description="Data/hora final. Padrao = momento da requisicao.",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista em texto",
     *         @OA\MediaType(
     *             mediaType="text/plain",
     *             @OA\Schema(
     *                 type="string",
     *                 example="48999998947;Maria\n47999507686;Fabiana"
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha ao contatar CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $params = $request->getQueryParams();

        $startRaw = $this->sanitizeScalar($params['schedule_start'] ?? ($params['start'] ?? null));
        if ($startRaw === null || $startRaw === '') {
            $startRaw = self::DEFAULT_SCHEDULE_START;
        }

        $endRaw = $this->sanitizeScalar($params['schedule_end'] ?? ($params['end'] ?? null));

        $start = $this->parseDateTime($startRaw);
        if ($start === null) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'schedule_start',
                'message' => 'Formato de data/hora invalido.',
            ]]);
        }

        $end = $endRaw === null || $endRaw === ''
            ? new DateTimeImmutable('now')
            : $this->parseDateTime($endRaw);

        if ($end === null) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'schedule_end',
                'message' => 'Formato de data/hora invalido.',
            ]]);
        }

        if ($end < $start) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'schedule_end',
                'message' => 'A data final deve ser maior ou igual a data inicial.',
            ]]);
        }

        try {
            $result = $this->exportService->exportContacts($start, $end, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('orders.schedule_contacts.unexpected', [
                'trace_id' => $traceId,
                'schedule_start' => $start->format('Y-m-d H:i:s'),
                'schedule_end' => $end->format('Y-m-d H:i:s'),
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Unexpected error while exporting schedule contacts.'
            );
        }

        $lines = $result['lines'] ?? [];
        $bodyString = implode("\n", $lines);
        $stream = Utils::streamFor($bodyString);

        return $response
            ->withBody($stream)
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/plain; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Trace-Id', $traceId)
            ->withHeader('X-Request-Id', $traceId)
            ->withHeader('X-Total-Lines', (string) count($lines));
    }

    private function sanitizeScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === false || $value === null) {
            return null;
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $formats = ['Y-m-d H:i:s', DateTimeImmutable::ATOM, DATE_RFC3339, 'Y-m-d'];
        foreach ($formats as $format) {
            $parsed = DateTimeImmutable::createFromFormat($format, $value);
            if ($parsed instanceof DateTimeImmutable) {
                return $parsed;
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}

