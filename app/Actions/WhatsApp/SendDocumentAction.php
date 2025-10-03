<?php

declare(strict_types=1);

namespace App\Actions\WhatsApp;

use App\Application\Services\WhatsAppService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ZapiRequestException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;
use RuntimeException;

final class SendDocumentAction
{
    public function __construct(
        private readonly WhatsAppService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $startedAt = microtime(true);
        $payload = $this->normalizePayload($request->getParsedBody());

        $validationErrors = $this->validate($payload);
        if ($validationErrors !== []) {
            return $this->responder
                ->validationError($response, $traceId, $validationErrors)
                ->withHeader('X-Request-Id', $traceId);
        }

        $phone = $this->sanitizePhone((string) $payload['phone']);
        $document = trim((string) $payload['document']);
        $extension = $this->sanitizeExtension((string) $payload['extension']);
        $options = $this->extractOptions($payload);

        try {
            $result = $this->service->sendDocument($traceId, $phone, $extension, $document, $options);
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
                    'Falha ao enviar documento via WhatsApp.',
                    502,
                    $details
                )
                ->withHeader('X-Request-Id', $traceId);
        } catch (RuntimeException $exception) {
            $this->logger->error('Unexpected error while sending WhatsApp document', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder
                ->internalError($response, $traceId, 'Erro interno ao enviar documento via WhatsApp.')
                ->withHeader('X-Request-Id', $traceId);
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $meta = [
            'page' => 1,
            'per_page' => 1,
            'count' => 1,
            'total' => 1,
            'extra' => array_filter([
                'source' => 'zapi',
                'elapsed_ms' => $elapsedMs,
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
            ->successResource($response, $result['data'], $traceId, $meta, $links)
            ->withHeader('X-Request-Id', $traceId);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $input): array
    {
        if (!is_array($input)) {
            return [];
        }

        return $input;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, string>>
     */
    private function validate(array $payload): array
    {
        $errors = [];

        $phone = $this->sanitizePhone((string) ($payload['phone'] ?? ''));
        try {
            v::stringType()->digit()->length(10, 15)->setName('phone')->assert($phone);
        } catch (NestedValidationException $exception) {
            $errors[] = [
                'field' => 'phone',
                'message' => 'Telefone deve conter entre 10 e 15 dígitos.',
            ];
        }

        $documentValue = trim((string) ($payload['document'] ?? ''));
        try {
            v::stringType()->notEmpty()->setName('document')->assert($documentValue);
            if (!$this->isValidDocumentInput($documentValue)) {
                throw new NestedValidationException();
            }
        } catch (NestedValidationException $exception) {
            $errors[] = [
                'field' => 'document',
                'message' => 'Documento deve ser uma URL http(s) válida ou data URI.',
            ];
        }

        $extension = (string) ($payload['extension'] ?? '');
        if ($this->sanitizeExtension($extension) === '') {
            $errors[] = [
                'field' => 'extension',
                'message' => 'Informe a extensão do documento (ex.: pdf).',
            ];
        }

        if (array_key_exists('fileName', $payload) && $payload['fileName'] !== null) {
            try {
                v::stringType()->length(1, 255)->setName('fileName')->assert($payload['fileName']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'fileName',
                    'message' => 'fileName deve possuir até 255 caracteres.',
                ];
            }
        }

        if (array_key_exists('caption', $payload) && $payload['caption'] !== null) {
            try {
                v::stringType()->length(0, 3000)->setName('caption')->assert($payload['caption']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'caption',
                    'message' => 'Legenda deve possuir até 3000 caracteres.',
                ];
            }
        }

        if (array_key_exists('messageId', $payload) && $payload['messageId'] !== null) {
            try {
                v::stringType()->notEmpty()->setName('messageId')->assert($payload['messageId']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'messageId',
                    'message' => 'messageId deve ser uma string não vazia.',
                ];
            }
        }

        if (array_key_exists('delayMessage', $payload)) {
            try {
                v::intVal()->between(1, 15)->setName('delayMessage')->assert($payload['delayMessage']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'delayMessage',
                    'message' => 'Informe um número entre 1 e 15.',
                ];
            }
        }

        if (array_key_exists('editDocumentMessageId', $payload) && $payload['editDocumentMessageId'] !== null) {
            try {
                v::stringType()->notEmpty()->setName('editDocumentMessageId')->assert($payload['editDocumentMessageId']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'editDocumentMessageId',
                    'message' => 'editDocumentMessageId deve ser uma string não vazia.',
                ];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function extractOptions(array $payload): array
    {
        $options = [];

        if (array_key_exists('fileName', $payload)) {
            $options['fileName'] = $this->sanitizeOptionalString($payload['fileName']);
        }

        if (array_key_exists('caption', $payload)) {
            $options['caption'] = $this->sanitizeOptionalString($payload['caption']);
        }

        if (array_key_exists('messageId', $payload)) {
            $options['messageId'] = $this->sanitizeOptionalString($payload['messageId']);
        }

        if (array_key_exists('delayMessage', $payload)) {
            $options['delayMessage'] = (int) $payload['delayMessage'];
        }

        if (array_key_exists('editDocumentMessageId', $payload)) {
            $options['editDocumentMessageId'] = $this->sanitizeOptionalString($payload['editDocumentMessageId']);
        }

        return array_filter($options, static fn ($value) => $value !== null && $value !== '');
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function sanitizeExtension(string $extension): string
    {
        return strtolower(ltrim(trim($extension), '.'));
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

    private function isValidDocumentInput(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_starts_with($value, 'data:')) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false;
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
