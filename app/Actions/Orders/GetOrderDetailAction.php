<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Application\Services\OrderService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Slim\Routing\RouteContext;

final class GetOrderDetailAction
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $uuid = $this->resolveUuid($request);

        if ($uuid === '') {
            return $this->responder->validationError($response, $traceId, [
                ['field' => 'uuid', 'message' => 'Identificador do pedido e obrigatorio.'],
            ]);
        }

        try {
            $data = $this->orderService->fetchOrderDetail($uuid, $traceId);
        } catch (CrmUnavailableException) {
            return $this->responder->badGateway($response, $traceId, 'CRM timeout');
        } catch (CrmRequestException $exception) {
            return $this->responder->badGateway(
                $response,
                $traceId,
                sprintf('CRM error (status %d).', $exception->getStatusCode())
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while fetching order detail', [
                'trace_id' => $traceId,
                'uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Unexpected error while fetching order detail.');
        }

        $resource = is_array($data) ? $data : ['data' => $data];

        return $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'crm'],
            ['self' => (string) $request->getUri()]
        );
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    private function resolveUuid(Request $request): string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $uuid = $route?->getArgument('uuid');

        return is_string($uuid) ? trim($uuid) : '';
    }
}

