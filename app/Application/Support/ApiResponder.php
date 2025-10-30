<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Settings\Settings;
use Psr\Http\Message\ResponseInterface;

final class ApiResponder
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param array<int, mixed>|array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    public function success(ResponseInterface $response, array $data, array $meta, array $links, string $traceId): ResponseInterface
    {
        return $this->withJson($response, [
            'success' => true,
            'data' => $data,
            'meta' => $this->normalizeMeta($meta),
            'links' => $this->normalizeLinks($links),
            'trace_id' => $traceId,
        ], 200, $traceId);
    }

    /**
     * @param array<int, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    public function successList(ResponseInterface $response, array $data, array $meta, array $links, string $traceId): ResponseInterface
    {
        return $this->withJson($response, [
            'success' => true,
            'data' => $data,
            'meta' => $this->normalizeMeta($meta),
            'links' => $this->normalizeLinks($links),
            'trace_id' => $traceId,
        ], 200, $traceId);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    public function successResource(
        ResponseInterface $response,
        array $data,
        string $traceId,
        array $meta = [],
        array $links = []
    ): ResponseInterface {
        return $this->withJson($response, [
            'success' => true,
            'data' => $data,
            'meta' => $this->normalizeMeta($meta),
            'links' => $this->normalizeLinks($links),
            'trace_id' => $traceId,
        ], 200, $traceId);
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    public function validationError(ResponseInterface $response, string $traceId, array $errors): ResponseInterface
    {
        return $this->error(
            $response,
            $traceId,
            'unprocessable_entity',
            'Parametros invalidos',
            422,
            ['errors' => $errors]
        );
    }

    public function conflict(ResponseInterface $response, string $traceId, string $message): ResponseInterface
    {
        return $this->error($response, $traceId, 'conflict', $message, 409);
    }

    public function notFound(ResponseInterface $response, string $traceId, string $message = 'Recurso nao encontrado'): ResponseInterface
    {
        return $this->error($response, $traceId, 'not_found', $message, 404);
    }

    public function unauthorized(ResponseInterface $response, string $traceId, string $detail): ResponseInterface
    {
        return $this->error($response, $traceId, 'unauthorized', $detail, 401);
    }

    public function tooManyRequests(ResponseInterface $response, string $traceId, string $detail, int $retryAfter): ResponseInterface
    {
        $response = $this->error($response, $traceId, 'too_many_requests', $detail, 429);

        return $response->withHeader('Retry-After', (string) $retryAfter);
    }

    public function badGateway(ResponseInterface $response, string $traceId, string $detail): ResponseInterface
    {
        return $this->error($response, $traceId, 'bad_gateway', $detail, 502);
    }

    public function internalError(ResponseInterface $response, string $traceId, string $detail = 'Internal Server Error'): ResponseInterface
    {
        return $this->error($response, $traceId, 'internal_error', $detail, 500);
    }

    public function error(
        ResponseInterface $response,
        string $traceId,
        string $code,
        string $message,
        int $status,
        array $details = []
    ): ResponseInterface {
        return $this->withJson($response, [
            'success' => false,
            'error' => array_merge([
                'code' => $code,
                'message' => $message,
            ], $details),
            'trace_id' => $traceId,
        ], $status, $traceId);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function normalizeMeta(array $meta): array
    {
        $normalized = [
            'page' => $meta['page'] ?? 1,
            'per_page' => $meta['per_page'] ?? $meta['size'] ?? null,
            'total' => $meta['total'] ?? $meta['total_items'] ?? null,
            'source' => $meta['source'] ?? 'api',
        ];

        if (isset($meta['count'])) {
            $normalized['count'] = $meta['count'];
        }

        if (isset($meta['total_pages'])) {
            $normalized['total_pages'] = $meta['total_pages'];
        }

        if (isset($meta['elapsed_ms'])) {
            $normalized['elapsed_ms'] = $meta['elapsed_ms'];
        }

        if (isset($meta['filters']) && is_array($meta['filters'])) {
            $normalized['filters'] = $meta['filters'];
        }

        if (isset($meta['extra']) && is_array($meta['extra'])) {
            $normalized = array_merge($normalized, $meta['extra']);
        }

        return array_filter($normalized, static fn ($value) => $value !== null);
    }

    /**
     * @param array<string, mixed> $links
     * @return array<string, mixed>
     */
    private function normalizeLinks(array $links): array
    {
        return [
            'self' => $links['self'] ?? '',
            'next' => $links['next'] ?? null,
            'prev' => $links['prev'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function withJson(ResponseInterface $response, array $payload, int $status, string $traceId): ResponseInterface
    {
        $encoded = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $body->write($encoded);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Trace-Id', $traceId);
    }
}

