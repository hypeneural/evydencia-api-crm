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

final class RemoveChatTagAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Put(
     *     path="/v1/whatsapp/chats/{phone}/tags/{tag}/remove",
     *     tags={"WhatsApp"},
     *     summary="Remove uma etiqueta de um chat do WhatsApp",
     *     @OA\Parameter(name="phone", in="path", required=true, @OA\Schema(type="string", example="5511999999999")),
     *     @OA\Parameter(name="tag", in="path", required=true, @OA\Schema(type="string", example="Cliente")),
     *     @OA\Response(response=200, description="Etiqueta removida", @OA\JsonContent(ref="#/components/schemas/WhatsAppGenericResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        [$phone, $tag, $errors] = $this->resolveParameters($request);

        if ($errors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $errors)
                ->withHeader('X-Request-Id', $traceId);
        }

        try {
            $result = $this->service->removeChatTag($traceId, $phone, $tag);
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
                    'Falha ao remover etiqueta no WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while removing WhatsApp chat tag', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao remover etiqueta no WhatsApp.')
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

    /**
     * @return array{0:?string,1:?string,2:array<int, array{field:string, message:string}>}
     */
    private function resolveParameters(Request $request): array
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $rawPhone = $route?->getArgument('phone');
        $rawTag = $route?->getArgument('tag');
        $errors = [];

        $phone = null;
        if (is_string($rawPhone)) {
            $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';
            try {
                v::stringType()->digit()->length(10, 15)->setName('phone')->assert($digits);
                $phone = $digits;
            } catch (NestedValidationException $exception) {
                $errors[] = ['field' => 'phone', 'message' => 'Telefone deve conter entre 10 e 15 digitos.'];
            }
        } else {
            $errors[] = ['field' => 'phone', 'message' => 'Telefone deve ser informado.'];
        }

        $tag = null;
        if (is_string($rawTag)) {
            $normalizedTag = trim($rawTag);
            try {
                v::stringType()->notEmpty()->length(1, 255)->setName('tag')->assert($normalizedTag);
                $tag = $normalizedTag;
            } catch (NestedValidationException $exception) {
                $errors[] = ['field' => 'tag', 'message' => 'tag deve possuir entre 1 e 255 caracteres.'];
            }
        } else {
            $errors[] = ['field' => 'tag', 'message' => 'tag deve ser informada.'];
        }

        return [$phone, $tag, $errors];
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
