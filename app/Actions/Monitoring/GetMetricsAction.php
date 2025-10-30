<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Infrastructure\Metrics\MetricsService;
use App\Settings\Settings;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class GetMetricsAction
{
    public function __construct(
        private readonly MetricsService $metrics,
        private readonly Settings $settings
    ) {
    }

    public function __invoke(Request $request, Response $response): Response
    {
        $config = $this->settings->getMetrics();
        if (!($config['enabled'] ?? false)) {
            return $response->withStatus(404);
        }

        $authToken = $config['auth_token'] ?? null;
        if ($authToken !== null && $authToken !== '') {
            $provided = $this->extractBearerToken($request);
            if ($provided === null || !hash_equals($authToken, $provided)) {
                return $response
                    ->withStatus(401)
                    ->withHeader('WWW-Authenticate', 'Bearer');
            }
        }

        $payload = $this->metrics->renderMetrics();
        $response->getBody()->write($payload);

        return $response
            ->withHeader('Content-Type', 'text/plain; version=0.0.4')
            ->withStatus(200);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (is_string($header) && stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            if ($token !== '') {
                return $token;
            }
        }

        $queryToken = $request->getQueryParams()['token'] ?? null;
        if (is_string($queryToken) && $queryToken !== '') {
            return $queryToken;
        }

        return null;
    }
}
