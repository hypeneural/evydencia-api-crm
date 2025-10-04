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

final class AddContactsAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @OA\Post(
     *     path="/v1/whatsapp/contacts/add",
     *     tags={"WhatsApp"},
     *     summary="Adiciona contatos na agenda vinculada ao WhatsApp",
     *     @OA\RequestBody(required=true, @OA\JsonContent(type="array", @OA\Items(ref="#/components/schemas/WhatsAppContactEntry"))),
     *     @OA\Response(response=200, description="Contatos enviados", @OA\JsonContent(ref="#/components/schemas/WhatsAppGenericResponse")),
     *     @OA\Response(response=422, description="Parametros invalidos", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=502, description="Falha no provedor Z-API", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")),
     *     @OA\Response(response=500, description="Erro interno", @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope"))
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        $validationErrors = $this->validate($payload);
        if ($validationErrors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $validationErrors)
                ->withHeader('X-Request-Id', $traceId);
        }

        $contacts = $this->sanitizeContacts($payload);

        try {
            $result = $this->service->addContacts($traceId, $contacts);
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
                    'Falha ao adicionar contatos no WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while adding WhatsApp contacts', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao adicionar contatos no WhatsApp.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $meta = [
            'page' => 1,
            'per_page' => count($contacts),
            'count' => count($contacts),
            'total' => count($contacts),
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

    private function normalizePayload(mixed $payload): array
    {
        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<int, mixed> $payload
     * @return array<int, array{field:string, message:string}>
     */
    private function validate(array $payload): array
    {
        if ($payload === []) {
            return [['field' => 'contacts', 'message' => 'Informe ao menos um contato.']];
        }

        $errors = [];

        foreach ($payload as $index => $item) {
            if (!is_array($item)) {
                $errors[] = ['field' => sprintf('contacts[%d]', $index), 'message' => 'Contato deve ser um objeto.'];
                continue;
            }

            try {
                v::stringType()->notEmpty()->length(1, 255)->setName('firstName')->assert($item['firstName'] ?? null);
            } catch (NestedValidationException $exception) {
                $errors[] = ['field' => sprintf('contacts[%d].firstName', $index), 'message' => 'firstName deve ser informado.'];
            }

            if (array_key_exists('lastName', $item) && $item['lastName'] !== null) {
                try {
                    v::stringType()->length(0, 255)->setName('lastName')->assert($item['lastName']);
                } catch (NestedValidationException $exception) {
                    $errors[] = ['field' => sprintf('contacts[%d].lastName', $index), 'message' => 'lastName deve possuir ate 255 caracteres.'];
                }
            }

            $phone = $this->sanitizePhone((string) ($item['phone'] ?? ''));
            try {
                v::stringType()->digit()->length(10, 15)->setName('phone')->assert($phone);
            } catch (NestedValidationException $exception) {
                $errors[] = ['field' => sprintf('contacts[%d].phone', $index), 'message' => 'phone deve conter entre 10 e 15 digitos.'];
            }
        }

        return $errors;
    }

    /**
     * @param array<int, array<string, mixed>> $payload
     * @return array<int, array<string, mixed>>
     */
    private function sanitizeContacts(array $payload): array
    {
        $contacts = [];

        foreach ($payload as $item) {
            if (!is_array($item)) {
                continue;
            }

            $contact = [
                'firstName' => trim((string) ($item['firstName'] ?? '')),
                'phone' => $this->sanitizePhone((string) ($item['phone'] ?? '')),
            ];

            if (array_key_exists('lastName', $item)) {
                $lastName = $this->sanitizeOptionalString($item['lastName']);
                if ($lastName !== null) {
                    $contact['lastName'] = $lastName;
                }
            }

            $contacts[] = $contact;
        }

        return $contacts;
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
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
