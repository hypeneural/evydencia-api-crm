<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use App\Actions\Reports\Concerns\HandlesSimpleReportAction;
use App\Application\Services\ReportService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetChristmasOrdersWithoutParticipantsAction
{
    use HandlesSimpleReportAction;

    public function __construct(
        private readonly ReportService $reportService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);

        try {
            $result = $this->reportService->fetchChristmasOrdersWithoutParticipants($traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while fetching christmas orders without participants report', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Unexpected error while fetching christmas orders without participants report.'
            );
        }

        $data = $this->normalizeList($result['data'] ?? []);
        $meta = $result['meta'] ?? [];
        $meta['count'] ??= count($data);
        $meta['page'] ??= 1;
        $meta['per_page'] ??= count($data);
        $meta['source'] ??= 'crm';

        $links = $this->buildLinks($request, $result['crm_links'] ?? []);

        return $this->responder->successList(
            $response,
            $data,
            $meta,
            $links,
            $traceId
        );
    }
}

