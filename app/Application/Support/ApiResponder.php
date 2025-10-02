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
     * @param array<int, mixed> $data
     * @param array<string, mixed> $meta
     * @param array<string, mixed> $links
     */
    public function successList(ResponseInterface $response, array $data, array $meta, array $links, string $traceId, string $endpoint): ResponseInterface
    {
        $baseMeta = [
            'page' => 1,
            'size' => count($data),
            'count' => count($data),
            'total_items' => null,
            'total_pages' => null,
            'elapsed_ms' => 0,
        ];
        $metaPayload = array_merge($baseMeta, $meta);

        $baseLinks = [
            'self' => '',
            'first' => '',
            'prev' => null,
            'next' => null,
            'last' => null,
        ];
        $linksPayload = array_merge($baseLinks, $links);

        $payload = [
            'data' => $data,
            'meta' => $metaPayload,
            'links' => $linksPayload,
            'trace_id' => $traceId,
            'source' => [
                'system' => 'crm',
                'endpoint' => $endpoint,
            ],
        ];

        return $this->withJson($response, $payload, 200, $traceId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function successResource(ResponseInterface $response, array $data, string $traceId, string $endpoint): ResponseInterface
    {
        $payload = [
            'data' => $data,
            'trace_id' => $traceId,
            'source' => [
                'system' => 'crm',
                'endpoint' => $endpoint,
            ],
        ];

        return $this->withJson($response, $payload, 200, $traceId);
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    public function validationError(ResponseInterface $response, string $traceId, array $errors): ResponseInterface
    {
        return $this->problem(
            $response,
            422,
            'Parâmetros inválidos',
            'Alguns parâmetros estão inválidos ou ausentes.',
            $traceId,
            $this->buildType('validation'),
            ['errors' => $errors]
        );
    }

    public function unauthorized(ResponseInterface $response, string $traceId, string $detail): ResponseInterface
    {
        return $this->problem(
            $response,
            401,
            'Unauthorized',
            $detail,
            $traceId,
            $this->buildType('unauthorized')
        );
    }

    public function tooManyRequests(ResponseInterface $response, string $traceId, string $detail, int $retryAfter): ResponseInterface
    {
        $response = $this->problem(
            $response,
            429,
            'Too Many Requests',
            $detail,
            $traceId,
            $this->buildType('rate-limit')
        );

        return $response->withHeader('Retry-After', (string) $retryAfter);
    }

    public function badGateway(ResponseInterface $response, string $traceId, string $detail): ResponseInterface
    {
        return $this->problem($response, 502, 'Bad Gateway', $detail, $traceId);
    }

    public function internalError(ResponseInterface $response, string $traceId, string $detail = 'Internal Server Error'): ResponseInterface
    {
        return $this->problem($response, 500, 'Internal Server Error', $detail, $traceId);
    }

    public function problem(
        ResponseInterface $response,
        int $status,
        string $title,
        string $detail,
        string $traceId,
        string $type = 'about:blank',
        array $extra = []
    ): ResponseInterface {
        $payload = array_merge(
            [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
                'trace_id' => $traceId,
            ],
            $extra
        );

        return $this->withProblemJson($response, $payload, $status, $traceId);
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
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Trace-Id', $traceId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function withProblemJson(ResponseInterface $response, array $payload, int $status, string $traceId): ResponseInterface
    {
        $encoded = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $body->write($encoded);

        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/problem+json')
            ->withHeader('Trace-Id', $traceId);
    }

    private function buildType(string $slug): string
    {
        $app = $this->settings->getApp();
        $baseUrl = $app['url'] ?? 'http://localhost';

        return rtrim($baseUrl, '/') . '/errors/' . $slug;
    }
}
