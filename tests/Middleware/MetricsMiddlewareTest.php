<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Infrastructure\Metrics\MetricsService;
use App\Middleware\MetricsMiddleware;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

final class MetricsMiddlewareTest extends TestCase
{
    public function testRecordsMetricsForSuccessfulRequest(): void
    {
        $settings = new Settings([
            'metrics' => [
                'enabled' => true,
                'namespace' => 'test_middleware',
                'adapter' => 'in-memory',
            ],
        ]);

        $service = new MetricsService(new CollectorRegistry(new InMemory()), $settings);
        $middleware = new MetricsMiddleware($service);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/v1/escolas');
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withStatus(204);
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(204, $response->getStatusCode());

        $metrics = $service->renderMetrics();
        self::assertStringContainsString('http_requests_total', $metrics);
        self::assertStringContainsString('status="204"', $metrics);
    }
}
