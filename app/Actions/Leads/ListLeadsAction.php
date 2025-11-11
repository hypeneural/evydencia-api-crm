<?php

declare(strict_types=1);

namespace App\Actions\Leads;

use App\Application\Services\LeadOverviewService;
use App\Application\Support\ApiResponder;
use App\Application\Support\LeadOverviewRequestMapper;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ListLeadsAction
{
    private const FORMAT_JSON = 'json';
    private const FORMAT_CSV = 'csv';
    private const LOST_LABEL = 'perdido';

    public function __construct(
        private readonly LeadOverviewService $leadOverviewService,
        private readonly LeadOverviewRequestMapper $requestMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/leads",
     *     tags={"Leads"},
     *     summary="Lista os contatos dos leads em JSON ou CSV",
     *     @OA\Parameter(
     *         name="campaign_id",
     *         in="query",
     *         required=true,
     *         description="Um ou mais IDs de campanha (separados por virgula).",
     *         @OA\Schema(type="string", example="416,415")
     *     ),
     *     @OA\Parameter(
     *         name="limit",
     *         in="query",
     *         required=false,
     *         description="Quantidade de leads a retornar (padrao 10, maximo 100).",
     *         @OA\Schema(type="integer", minimum=1, maximum=100, default=10)
     *     ),
     *     @OA\Parameter(
     *         name="date_from",
     *         in="query",
     *         required=false,
     *         description="Filtro de data inicial (lead.created_at) em ISO-8601.",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="date_to",
     *         in="query",
     *         required=false,
     *         description="Filtro de data final (lead.created_at) em ISO-8601.",
     *         @OA\Schema(type="string", format="date-time")
     *     ),
     *     @OA\Parameter(
     *         name="dedupe_by",
     *         in="query",
     *         required=false,
     *         description="Chave usada para desduplicar leads (lead_id ou whatsapp).",
     *         @OA\Schema(type="string", enum={"lead_id","whatsapp"}, default="lead_id")
     *     ),
     *     @OA\Parameter(
     *         name="format",
     *         in="query",
     *         required=false,
     *         description="Formato desejado para a saida.",
     *         @OA\Schema(type="string", enum={"json","csv"}, default="json")
     *     ),
     *     @OA\Parameter(
     *         name="order_by",
     *         in="query",
     *         required=false,
     *         description="Campo usado para ordenar o resultado (created_at ou updated_at).",
     *         @OA\Schema(type="string", enum={"created_at","updated_at"}, default="created_at")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lista de leads pronta para exportacao",
     *         @OA\MediaType(
     *             mediaType="application/json",
     *             @OA\Schema(ref="#/components/schemas/GenericResourceResponse")
     *         ),
     *         @OA\MediaType(
     *             mediaType="text/csv",
     *             @OA\Schema(type="string", format="binary")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Erro ao consultar o CRM", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $query = $request->getQueryParams();

        $format = isset($query['format']) ? strtolower(trim((string) $query['format'])) : self::FORMAT_JSON;
        if (!in_array($format, [self::FORMAT_JSON, self::FORMAT_CSV], true)) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'format',
                'message' => 'Formato invalido. Use json ou csv.',
            ]]);
        }

        $orderBy = isset($query['order_by']) ? strtolower(trim((string) $query['order_by'])) : 'created_at';
        if (!in_array($orderBy, ['created_at', 'updated_at'], true)) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'order_by',
                'message' => 'order_by deve ser created_at ou updated_at.',
            ]]);
        }

        try {
            $options = $this->requestMapper->map($query);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $recentLeads = $this->leadOverviewService->listRecentLeads($options, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while listing leads', [
                'trace_id' => $traceId,
                'campaign_ids' => $options->campaignIds,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Unexpected error while listing leads.');
        }

        $normalized = $this->prepareLeads($recentLeads, $orderBy, $options->limit);

        if ($format === self::FORMAT_CSV) {
            return $this->respondCsv($response, $normalized, $traceId);
        }

        return $this->responder->successResource(
            $response,
            ['leads' => $normalized],
            $traceId,
            [
                'count' => count($normalized),
                'source' => 'crm',
                'filters' => [
                    'order_by' => $orderBy,
                    'format' => $format,
                ],
            ],
            ['self' => (string) $request->getUri()]
        );
    }

    private function prepareLeads(array $leads, string $orderBy, int $limit): array
    {
        $filtered = array_filter($leads, function (mixed $lead): bool {
            if (!is_array($lead)) {
                return false;
            }

            return !$this->isLostLead($lead);
        });

        $normalized = array_map(function (array $lead): array {
            return [
                'phone' => $this->normalizePhone($lead['whatsapp'] ?? ''),
                'name' => $this->normalizeName($lead['name'] ?? null),
                'created_at' => $lead['created_at'] ?? null,
                'updated_at' => $lead['updated_at'] ?? null,
            ];
        }, array_values($filtered));

        usort($normalized, fn (array $left, array $right): int => $this->compareRecords($left, $right, $orderBy));

        $limited = array_slice($normalized, 0, $limit);

        return array_map(static fn (array $lead): array => [
            'phone' => $lead['phone'],
            'name' => $lead['name'],
        ], $limited);
    }

    private function compareRecords(array $left, array $right, string $orderBy): int
    {
        $leftInstant = $this->parseDateToTimestamp($left[$orderBy] ?? null);
        $rightInstant = $this->parseDateToTimestamp($right[$orderBy] ?? null);

        if ($leftInstant !== null && $rightInstant !== null) {
            $comparison = $rightInstant <=> $leftInstant;
            if ($comparison !== 0) {
                return $comparison;
            }
        } elseif ($leftInstant !== null) {
            return -1;
        } elseif ($rightInstant !== null) {
            return 1;
        }

        return strcmp((string) $right['phone'], (string) $left['phone']);
    }

    private function parseDateToTimestamp(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function normalizePhone(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits ?? '';
    }

    private function normalizeName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isLostLead(array $lead): bool
    {
        $status = $lead['status'] ?? [];
        if (!is_array($status)) {
            return false;
        }

        $label = isset($status['label_pt']) && is_string($status['label_pt'])
            ? strtolower(trim($status['label_pt']))
            : '';

        $name = isset($status['name']) && is_string($status['name'])
            ? strtolower(trim($status['name']))
            : '';

        return $label === self::LOST_LABEL || $name === self::LOST_LABEL;
    }

    private function respondCsv(Response $response, array $leads, string $traceId): Response
    {
        $handle = fopen('php://temp', 'rb+');

        if ($handle === false) {
            return $this->responder->internalError($response, $traceId, 'Unable to create CSV buffer.');
        }

        fputcsv($handle, ['phone', 'name']);
        foreach ($leads as $lead) {
            fputcsv($handle, [$lead['phone'], $lead['name'] ?? '']);
        }

        rewind($handle);
        $contents = stream_get_contents($handle) ?: '';
        fclose($handle);

        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $body->write($contents);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="leads_%s.csv"', date('Ymd_His')))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Trace-Id', $traceId);
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
