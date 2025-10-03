<?php

declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class CreateBlacklistEntryAction
{
    public function __construct(
        private readonly BlacklistService $service,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
        $idempotencyKey = trim($idempotencyKey) === '' ? null : $idempotencyKey;

        $payload = $this->preparePayload($payload);

        try {
            $result = $this->service->create($payload, $traceId, $idempotencyKey);
        } catch (ValidationException $exception) {
            return $this->responder->validationError($response, $traceId, $exception->getErrors());
        } catch (ConflictException $exception) {
            return $this->responder->error(
                $response,
                $traceId,
                'conflict',
                $exception->getMessage(),
                409,
                ['errors' => $exception->getErrors()]
            );
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to create blacklist entry', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel criar o registro da blacklist.');
        }

        $resource = $result['resource'];
        $created = $result['created'] ?? false;

        $location = $this->buildLocation($request, $resource['id'] ?? null);

        $response = $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'api'],
            ['self' => $location]
        );

        if ($created) {
            $response = $response->withStatus(201);
        }

        if ($location !== null) {
            $response = $response->withHeader('Location', $location);
        }

        return $response->withHeader('X-Request-Id', $traceId);
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
     * @return array<string, mixed>
     */
    private function preparePayload(array $payload): array
    {
        if (isset($payload['name'])) {
            $payload['name'] = trim((string) $payload['name']);
        }

        if (isset($payload['whatsapp'])) {
            $payload['whatsapp'] = $this->sanitizeWhatsapp($payload['whatsapp']);
        }

        if (array_key_exists('has_closed_order', $payload)) {
            $payload['has_closed_order'] = $this->sanitizeBooleanInput($payload['has_closed_order']);
        }

        if (array_key_exists('observation', $payload)) {
            if ($payload['observation'] === null) {
                $payload['observation'] = null;
            } else {
                $payload['observation'] = trim((string) $payload['observation']);
            }
        }

        return $payload;
    }

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }

    private function sanitizeWhatsapp(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits ?? '';
    }

    private function sanitizeBooleanInput(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }

            return in_array($normalized, ['1', 'true', 'yes', 'sim'], true);
        }

        return false;
    }

    private function buildLocation(Request $request, mixed $id): ?string
    {
        $identifier = is_scalar($id) ? (string) $id : '';
        if ($identifier === '') {
            return null;
        }

        $uri = $request->getUri();
        $port = $uri->getPort();
        $authority = $uri->getHost() . ($port !== null ? ':' . $port : '');
        $basePath = rtrim(sprintf('%s://%s', $uri->getScheme(), $authority), '/');

        return $basePath . '/v1/blacklist/' . $identifier;
    }
}
