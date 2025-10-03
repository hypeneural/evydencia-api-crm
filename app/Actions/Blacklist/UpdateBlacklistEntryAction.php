<?php

declare(strict_types=1);

namespace App\Actions\Blacklist;

use App\Application\Services\BlacklistService;
use App\Application\Support\ApiResponder;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validator as v;
use RuntimeException;
use Slim\Routing\RouteContext;

final class UpdateBlacklistEntryAction
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
        $id = $this->resolveId($request);

        if ($id === null) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'id',
                'message' => 'Identificador invalido.',
            ]]);
        }

        $payload = $this->normalizePayload($request->getParsedBody());
        $validationErrors = $this->validate($payload);
        if ($validationErrors !== []) {
            return $this->responder->validationError($response, $traceId, $validationErrors);
        }

        if ($payload === []) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'body',
                'message' => 'Informe ao menos um campo para atualizar.',
            ]]);
        }

        $payload = $this->preparePayload($payload);

        try {
            $resource = $this->service->update($id, $payload, $traceId);
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
        } catch (NotFoundException $exception) {
            return $this->responder->notFound($response, $traceId, $exception->getMessage());
        } catch (RuntimeException $exception) {
            $this->logger->error('Failed to update blacklist entry', [
                'trace_id' => $traceId,
                'id' => $id,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->internalError($response, $traceId, 'Nao foi possivel atualizar o registro da blacklist.');
        }

        $response = $this->responder->successResource(
            $response,
            $resource,
            $traceId,
            ['source' => 'api'],
            ['self' => (string) $request->getUri()]
        );

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
     * @return array<int, array<string, string>>
     */
    private function validate(array $payload): array
    {
        $errors = [];

        if (array_key_exists('name', $payload)) {
            try {
                v::stringType()->notEmpty()->length(2, 255)->setName('name')->assert($payload['name']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'name',
                    'message' => $exception->getMessages()[0] ?? 'Nome invalido.',
                ];
            }
        }

        if (array_key_exists('whatsapp', $payload)) {
            try {
                v::stringType()->notEmpty()->regex('/^\\d{10,14}$/')->setName('whatsapp')->assert($this->sanitizeWhatsapp($payload['whatsapp']));
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'whatsapp',
                    'message' => 'Whatsapp deve conter apenas digitos (10 a 14 caracteres).',
                ];
            }
        }

        if (array_key_exists('has_closed_order', $payload)) {
            try {
                v::oneOf(
                    v::boolType(),
                    v::intType()->in([0, 1]),
                    v::stringType()->lowercase()->in(['0', '1', 'true', 'false', 'yes', 'no', 'sim', 'nao'])
                )->setName('has_closed_order')->assert($payload['has_closed_order']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'has_closed_order',
                    'message' => 'Valor invalido para has_closed_order.',
                ];
            }
        }

        if (array_key_exists('observation', $payload) && $payload['observation'] !== null) {
            try {
                v::stringType()->length(0, 1000)->setName('observation')->assert($payload['observation']);
            } catch (NestedValidationException $exception) {
                $errors[] = [
                    'field' => 'observation',
                    'message' => $exception->getMessages()[0] ?? 'Observacao invalida.',
                ];
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function preparePayload(array $payload): array
    {
        if (array_key_exists('name', $payload)) {
            $payload['name'] = trim((string) $payload['name']);
        }

        if (array_key_exists('whatsapp', $payload)) {
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

    private function resolveId(Request $request): ?int
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        $id = $route?->getArgument('id');
        if (!is_string($id)) {
            return null;
        }

        $intId = (int) filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $intId > 0 ? $intId : null;
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
}
