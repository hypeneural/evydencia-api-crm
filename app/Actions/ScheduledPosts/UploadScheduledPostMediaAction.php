<?php

declare(strict_types=1);

namespace App\Actions\ScheduledPosts;

use App\Application\Services\ScheduledPostMediaService;
use App\Application\Support\ApiResponder;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class UploadScheduledPostMediaAction
{
    public function __construct(
        private readonly ScheduledPostMediaService $mediaService,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = $this->resolveTraceId($request);
        $payload = $this->normalizePayload($request->getParsedBody());

        $type = isset($payload['type']) ? strtolower(trim((string) $payload['type'])) : null;
        $allowedTypes = $this->mediaService->getAllowedTypes();

        if ($type === null || !in_array($type, $allowedTypes, true)) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'type',
                'message' => sprintf('Informe um tipo válido (%s).', implode(', ', $allowedTypes)),
            ]]);
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = null;

        if (isset($uploadedFiles['media'])) {
            $candidate = $uploadedFiles['media'];
            $file = is_array($candidate) ? reset($candidate) : $candidate;
        } elseif ($uploadedFiles !== []) {
            $candidate = reset($uploadedFiles);
            $file = is_array($candidate) ? reset($candidate) : $candidate;
        }

        if (!$file instanceof UploadedFileInterface) {
            return $this->responder->validationError($response, $traceId, [[
                'field' => 'media',
                'message' => 'Nenhum arquivo foi enviado.',
            ]]);
        }

        try {
            $result = $this->mediaService->store($file, $type);
        } catch (RuntimeException $exception) {
            $this->logger->warning('Failed to upload scheduled post media', [
                'trace_id' => $traceId,
                'type' => $type,
                'error' => $exception->getMessage(),
            ]);

            return $this->responder->validationError($response, $traceId, [[
                'field' => 'media',
                'message' => $exception->getMessage(),
            ]]);
        }

        $response = $this->responder->successResource(
            $response,
            $result,
            $traceId,
            ['source' => 'api'],
            ['self' => (string) $request->getUri()]
        );

        return $response
            ->withStatus(201)
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

    private function resolveTraceId(Request $request): string
    {
        $traceId = $request->getAttribute('trace_id');

        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
        }

        return $traceId;
    }
}
