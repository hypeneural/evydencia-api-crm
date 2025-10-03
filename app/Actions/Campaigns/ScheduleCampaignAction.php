<?php

declare(strict_types=1);

namespace App\Actions\Campaigns;

use App\Application\Services\CampaignService;
use App\Application\Support\ApiResponder;
use App\Application\Support\CampaignSchedulePayloadNormalizer;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ScheduleCampaignAction
{
    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly CampaignSchedulePayloadNormalizer $payloadNormalizer,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $rawPayload = $this->normalizePayload($request->getParsedBody());

        if ($rawPayload === null) {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'body', 'message' => 'Payload deve ser um objeto JSON.'],
            ]);
        }

        try {
            $payload = $this->payloadNormalizer->normalize($rawPayload);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        }

        try {
            $result = $this->campaignService->scheduleCampaign($payload, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while scheduling campaign', [
                'trace_id' => $traceId,
                'payload_keys' => array_keys($payload),
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError(
                $response,
                $traceId,
                'Unexpected error while scheduling campaign.'
            );
        }

        $data = $result['data'] ?? [];
        if (!is_array($data)) {
            $data = ['result' => $data];
        }

        return $this->responder->successResource(
            $response,
            $data,
            $traceId,
            ['source' => 'crm'],
            ['self' => (string) $request->getUri()]
        );
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>|null
     */
    private function normalizePayload(mixed $input): ?array
    {
        if (!is_array($input)) {
            return null;
        }

        return $input;
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

