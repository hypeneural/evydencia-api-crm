<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Monitoring\GetMetricsAction;
use App\Infrastructure\Metrics\MetricsService;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class MetricsActionTest extends TestCase
{
    private function createService(array $config): MetricsService
    {
        $settings = new Settings($config);

        return new MetricsService(new CollectorRegistry(new InMemory()), $settings);
    }

    public function testReturns404WhenDisabled(): void
    {
        $service = $this->createService(['metrics' => ['enabled' => false]]);
        $settings = new Settings(['metrics' => ['enabled' => false]]);

        $action = new GetMetricsAction($service, $settings);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/metrics');
        $response = (new ResponseFactory())->createResponse();

        $result = $action($request, $response);

        self::assertSame(404, $result->getStatusCode());
    }

    public function testRequiresTokenWhenConfigured(): void
    {
        $config = [
            'metrics' => [
                'enabled' => true,
                'auth_token' => 'secret-token',
            ],
        ];
        $service = $this->createService($config);
        $settings = new Settings($config);

        $action = new GetMetricsAction($service, $settings);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/metrics');
        $response = (new ResponseFactory())->createResponse();

        $result = $action($request, $response);
        self::assertSame(401, $result->getStatusCode());

        $authorizedRequest = $request->withHeader('Authorization', 'Bearer secret-token');
        $authorizedResponse = $action($authorizedRequest, $response);

        self::assertSame(200, $authorizedResponse->getStatusCode());
        self::assertSame('text/plain; version=0.0.4', $authorizedResponse->getHeaderLine('Content-Type'));
    }
}