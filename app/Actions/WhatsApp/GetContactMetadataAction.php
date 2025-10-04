<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Application\Services\WhatsAppService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ZapiRequestException;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;
use RuntimeException;
use Slim\Routing\RouteContext;

final class GetContactMetadataAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/whatsapp/contacts/{phone}",
     *     tags={"WhatsApp"},
     *     summary="Obtem metadata de um contato do WhatsApp",
     *     @OA\Parameter(name="phone", in="path", required=true, @OA\Schema(type="string", example="5511999999999")),
     *     @OA\Response(response=200, description="Metadata do contato", @OA\JsonContent(ref="#/components/schemas/WhatsAppContactResourceResponse")),
     *     @OA\Response(response=422, description="Telefone invalido", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $phone = $this->resolvePhone($request);

        if ($phone === null) {
            return $this->responder
                ->validationError($response, $traceId, [[
                    'field' => 'phone',
                    'message' => 'Informe um telefone valido (apenas digitos).',
                ]])
                ->withHeader('X-Request-Id', $traceId);
        }

        try {
            $result = $this->service->getContactMetadata($traceId, $phone);
        } catch (ZapiRequestException $exception) {
            $details = [];
            if ($this->service->isDebug()) {
                $details['provider_status'] = $exception->getStatus();
                $details['provider_response'] = $exception->getBody();
            }

            return $this->responder
                ->error(
                    $response,
                    $traceId,
                    'bad_gateway',
                    'Falha ao consultar metadata do contato no WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while fetching WhatsApp contact metadata', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao consultar metadata do contato.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $meta = [
            'page' => 1,
            'per_page' => 1,
            'count' => 1,
            'total' => 1,
            'extra' => array_filter([
                'source' => 'zapi',
                'provider_status' => $result['meta']['provider_status'] ?? null,
                'provider_body' => $result['meta']['provider_body'] ?? null,
            ], static fn ($value) => $value !== null),
        ];

        $links = [
            'self' => (string) $request->getUri(),
            'next' => null,
            'prev' => null,
        ];

        return $this->responder
            ->successResource($response, (array) ($result['data'] ?? []), $traceId, $meta, $links)
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

    private function resolvePhone(Request $request): ?string
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $argument = $route?->getArgument('phone');
        if (!is_string($argument)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $argument) ?? '';

        try {
            v::stringType()->digit()->length(10, 15)->assert($digits);
        } catch (NestedValidationException $exception) {
            return null;
        }

        return $digits;
    }
}
