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

final class ListContactsAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/whatsapp/contacts",
     *     tags={"WhatsApp"},
     *     summary="Lista contatos sincronizados do WhatsApp",
     *     @OA\Parameter(name="page", in="query", required=true, @OA\Schema(type="integer", minimum=1)),
     *     @OA\Parameter(name="pageSize", in="query", required=true, @OA\Schema(type="integer", minimum=1, maximum=1000)),
     *     @OA\Response(response=200, description="Lista de contatos", @OA\JsonContent(ref="#/components/schemas/WhatsAppContactsListResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $query = $this->normalizeQuery($request->getQueryParams());

        $validationErrors = $this->validate($query);
        if ($validationErrors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $validationErrors)
                ->withHeader('X-Request-Id', $traceId);
        }

        $page = (int) $query['page'];
        $pageSize = (int) $query['pageSize'];

        try {
            $result = $this->service->getContacts($traceId, $page, $pageSize);
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
                    'Falha ao consultar contatos no WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while listing WhatsApp contacts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao listar contatos do WhatsApp.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $contacts = is_array($result['data']) ? $result['data'] : [];
        $count = count($contacts);
        $meta = [
            'page' => $page,
            'per_page' => $pageSize,
            'count' => $count,
            'total' => null,
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
            ->successList($response, $contacts, $meta, $links, $traceId)
            ->withHeader('X-Request-Id', $traceId);
    }

    private function normalizeQuery(?array $query): array
    {
        return $query ?? [];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, array{field:string, message:string}>
     */
    private function validate(array $query): array
    {
        $errors = [];

        $page = $query['page'] ?? null;
        try {
            v::intVal()->min(1)->setName('page')->assert($page);
        } catch (NestedValidationException $exception) {
            $errors[] = ['field' => 'page', 'message' => 'Informe a pagina (>=1).'];
        }

        $pageSize = $query['pageSize'] ?? null;
        try {
            v::intVal()->between(1, 1000)->setName('pageSize')->assert($pageSize);
        } catch (NestedValidationException $exception) {
            $errors[] = ['field' => 'pageSize', 'message' => 'pageSize deve estar entre 1 e 1000.'];
        }

        return $errors;
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
