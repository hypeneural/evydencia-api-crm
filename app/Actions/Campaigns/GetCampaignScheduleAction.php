<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Actions\Concerns\HandlesListAction;
use App\Application\Services\CampaignService;
use App\Application\Support\ApiResponder;
use App\Application\Support\QueryMapper;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class GetCampaignScheduleAction
{
    use HandlesListAction;

    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly QueryMapper $queryMapper,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);

        try {
            $options = $this->queryMapper->mapCampaignSchedule($request->getQueryParams());
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->campaignService->fetchSchedule($options, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while fetching campaign schedule', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Unexpected error while fetching campaign schedule.');
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $meta = $result['meta'];
        $meta['elapsed_ms'] = $elapsedMs;
        $links = $this->buildLinks($request, $options, $meta, $result['crm_links'] ?? []);

        return $this->responder->successList(
            $response,
            $result['data'],
            $meta,
            $links,
            $traceId
        );
    }
}

