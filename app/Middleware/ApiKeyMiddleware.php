<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Application\Support\ApiResponder;
use App\Settings\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;

final class ApiKeyMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ApiResponder $responder,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $traceId = $request->getAttribute('trace_id');
        if (!is_string($traceId) || $traceId === '') {
            $traceId = bin2hex(random_bytes(8));
            $request = $request->withAttribute('trace_id', $traceId);
        }

        $expected = $this->settings->getApiKey();
        if ($expected === null || $expected === '') {
            return $handler->handle($request);
        }

        $provided = $request->getHeaderLine('X-API-Key');

        if ($provided !== '' && hash_equals($expected, $provided)) {
            return $handler->handle($request);
        }

        $this->logger->warning('Unauthorized request blocked by API key middleware', [
            'trace_id' => $traceId,
            'ip' => $request->getServerParams()['REMOTE_ADDR'] ?? 'anonymous',
            'path' => $request->getUri()->getPath(),
        ]);

        $baseResponse = new Response(401);
        $response = $this->responder->unauthorized($baseResponse, $traceId, 'Invalid API key.');

        return $response->withHeader('WWW-Authenticate', 'ApiKey');
    }
}
