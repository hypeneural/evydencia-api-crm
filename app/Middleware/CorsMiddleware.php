<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

final class CorsMiddleware implements MiddlewareInterface
{
    /**
     * @var list<string>
     */
    private array $allowedOrigins;

    private bool $allowAllOrigins;

    /**
     * @var list<string>
     */
    private array $allowedMethods;

    /**
     * @var list<string>
     */
    private array $allowedOriginsLower;

    private bool $allowAllMethods;

    /**
     * @var list<string>
     */
    private array $allowedHeaders;

    /**
     * @var list<string>
     */
    private array $allowedHeadersLower;

    private bool $allowAllHeaders;

    /**
     * @var list<string>
     */
    private array $exposedHeaders;

    private bool $allowCredentials;

    private int $maxAge;

    /**
     * @param array{
     *     allow_all_origins: bool,
     *     allowed_origins: list<string>,
     *     allow_all_methods: bool,
     *     allowed_methods: list<string>,
     *     allow_all_headers: bool,
     *     allowed_headers: list<string>,
     *     exposed_headers: list<string>,
     *     allow_credentials: bool,
     *     max_age: int
     * } $options
     */
    public function __construct(array $options)
    {
        $this->allowAllOrigins = $options['allow_all_origins'] ?? false;
        $this->allowedOrigins = $this->normalizeList($options['allowed_origins'] ?? []);
        $this->allowedOriginsLower = array_map('strtolower', $this->allowedOrigins);

        $methods = $options['allowed_methods'] ?? ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];
        $methods = $this->normalizeList($methods);
        if (!in_array('OPTIONS', $methods, true)) {
            $methods[] = 'OPTIONS';
        }
        $this->allowAllMethods = $options['allow_all_methods'] ?? false;
        $this->allowedMethods = array_values(array_unique(array_map(static fn (string $method): string => strtoupper($method), $methods)));

        $this->allowAllHeaders = $options['allow_all_headers'] ?? false;
        $this->allowedHeaders = $this->normalizeList($options['allowed_headers'] ?? []);
        $this->allowedHeadersLower = array_map('strtolower', $this->allowedHeaders);

        $this->exposedHeaders = $this->normalizeList($options['exposed_headers'] ?? []);
        $this->allowCredentials = (bool) ($options['allow_credentials'] ?? false);
        $this->maxAge = max(0, (int) ($options['max_age'] ?? 0));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $this->extractOrigin($request);
        $method = strtoupper($request->getMethod());

        if ($origin === null || !$this->isOriginAllowed($origin)) {
            if ($method === 'OPTIONS') {
                return (new Response())->withStatus(204);
            }

            return $handler->handle($request);
        }

        if ($method === 'OPTIONS') {
            $responseMethod = strtoupper($request->getHeaderLine('Access-Control-Request-Method'));
            if ($responseMethod !== '' && !$this->isMethodAllowed($responseMethod)) {
                return (new Response())->withStatus(405);
            }

            if (!$this->areHeadersAllowed($request)) {
                return (new Response())->withStatus(400);
            }

            $response = new Response(204);
            return $this->applyCorsHeaders($request, $response, $origin, true);
        }

        $response = $handler->handle($request);

        return $this->applyCorsHeaders($request, $response, $origin, false);
    }

    /**
     * @param array<int, string> $values
     * @return list<string>
     */
    private function normalizeList(array $values): array
    {
        $normalized = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }

    private function extractOrigin(ServerRequestInterface $request): ?string
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin === '') {
            return null;
        }

        return $origin;
    }

    private function isOriginAllowed(string $origin): bool
    {
        if ($this->allowAllOrigins) {
            return true;
        }

        return in_array(strtolower($origin), $this->allowedOriginsLower, true);
    }

    private function isMethodAllowed(string $method): bool
    {
        if ($this->allowAllMethods) {
            return true;
        }

        return in_array(strtoupper($method), $this->allowedMethods, true);
    }

    private function areHeadersAllowed(ServerRequestInterface $request): bool
    {
        if ($this->allowAllHeaders) {
            return true;
        }

        $requested = $this->extractRequestedHeaders($request);
        if ($requested === []) {
            return true;
        }

        foreach ($requested as $header) {
            if (!in_array($header, $this->allowedHeadersLower, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function extractRequestedHeaders(ServerRequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeader('Access-Control-Request-Headers') as $line) {
            $lower = strtolower($line);
            foreach (explode(',', $lower) as $item) {
                $trimmed = trim($item);
                if ($trimmed !== '') {
                    $headers[] = $trimmed;
                }
            }
        }

        return array_values(array_unique($headers));
    }

    private function applyCorsHeaders(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $origin,
        bool $isPreflight
    ): ResponseInterface {
        $allowOrigin = $this->allowAllOrigins && !$this->allowCredentials ? '*' : $origin;

        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin);

        $response = $this->appendVaryHeader($response, 'Origin');

        if ($this->allowCredentials) {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        $methods = $this->allowAllMethods
            ? 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD'
            : implode(', ', $this->allowedMethods);
        $response = $response->withHeader('Access-Control-Allow-Methods', $methods);

        $allowHeaders = '';
        if ($this->allowAllHeaders) {
            $requested = $this->extractRequestedHeaders($request);
            $allowHeaders = $requested === [] ? '*' : implode(', ', array_unique($requested));
        } elseif ($this->allowedHeaders !== []) {
            $allowHeaders = implode(', ', $this->allowedHeaders);
        }

        if ($allowHeaders !== '') {
            $response = $response->withHeader('Access-Control-Allow-Headers', $allowHeaders);
        }

        if ($this->exposedHeaders !== []) {
            $response = $response->withHeader('Access-Control-Expose-Headers', implode(', ', $this->exposedHeaders));
        }

        if ($isPreflight && $this->maxAge > 0) {
            $response = $response->withHeader('Access-Control-Max-Age', (string) $this->maxAge);
        }

        return $response;
    }

    private function appendVaryHeader(ResponseInterface $response, string $value): ResponseInterface
    {
        $current = $response->getHeaderLine('Vary');
        if ($current === '') {
            return $response->withHeader('Vary', $value);
        }

        $items = array_map('trim', explode(',', $current));
        if (in_array($value, $items, true)) {
            return $response;
        }

        $items[] = $value;

        return $response->withHeader('Vary', implode(', ', $items));
    }
}
