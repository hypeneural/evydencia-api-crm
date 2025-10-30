<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Metrics;

use App\Infrastructure\Metrics\MetricsService;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;

final class MetricsServiceTest extends TestCase
{
    public function testObserveHttpRequestIsRecorded(): void
    {
        $settings = new Settings([
            'metrics' => [
                'enabled' => true,
                'namespace' => 'test_metrics',
                'adapter' => 'in-memory',
                'http_buckets' => [0.1, 0.5],
            ],
        ]);

        $service = new MetricsService(
            new CollectorRegistry(new InMemory()),
            $settings
        );

        $service->observeHttpRequest('GET', '/v1/escolas/123', 200, 'mobile', 0.2);
        $service->observeHttpRequest('GET', '/v1/escolas/123', 200, 'mobile', 0.3);

        $metrics = $service->renderMetrics();

        self::assertStringContainsString('http_requests_total', $metrics);
        self::assertStringContainsString('route="/v1/escolas/123"', $metrics);
        self::assertStringContainsString('client="mobile"', $metrics);
    }

    public function testRenderMetricsReturnsEmptyWhenDisabled(): void
    {
        $settings = new Settings([
            'metrics' => ['enabled' => false],
        ]);

        $service = new MetricsService(
            new CollectorRegistry(new InMemory()),
            $settings
        );

        self::assertSame('', $service->renderMetrics());
    }
}

