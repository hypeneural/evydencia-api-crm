<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Settings\Settings;
use Prometheus\CollectorRegistry;
use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\RenderTextFormat;

final class MetricsService
{
    private bool $enabled;

    private CollectorRegistry $registry;

    private string $namespace;

    private ?Counter $httpRequests = null;

    private ?Histogram $httpDuration = null;

    /** @var float[] */
    private array $buckets;

    public function __construct(CollectorRegistry $registry, Settings $settings)
    {
        $config = $settings->getMetrics();

        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->namespace = $config['namespace'] ?? 'escola_connect_api';
        $this->registry = $registry;
        $this->buckets = $this->resolveBuckets($config['http_buckets'] ?? []);

        if ($this->enabled) {
            $this->httpRequests = $this->registry->getOrRegisterCounter(
                $this->namespace,
                'http_requests_total',
                'Total HTTP requests processed by Escola Connect API',
                ['method', 'route', 'status', 'client']
            );

            $this->httpDuration = $this->registry->getOrRegisterHistogram(
                $this->namespace,
                'http_request_duration_seconds',
                'HTTP request duration in seconds',
                ['method', 'route', 'client'],
                $this->buckets
            );
        }
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function observeHttpRequest(
        string $method,
        string $route,
        int $statusCode,
        string $client,
        float $durationSeconds
    ): void {
        if (!$this->enabled || $this->httpRequests === null || $this->httpDuration === null) {
            return;
        }

        $labelsRoute = $this->normalizeLabel($route);
        $labelsClient = $this->normalizeLabel($client);

        $this->httpRequests->inc([
            strtoupper($method),
            $labelsRoute,
            (string) $statusCode,
            $labelsClient,
        ]);

        $this->httpDuration->observe(
            max($durationSeconds, 0.0),
            [
                strtoupper($method),
                $labelsRoute,
                $labelsClient,
            ]
        );
    }

    public function renderMetrics(): string
    {
        if (!$this->enabled) {
            return '';
        }

        $renderer = new RenderTextFormat();

        return $renderer->render($this->registry->getMetricFamilySamples());
    }

    private function normalizeLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9:_\/-]+/', '_', $normalized) ?? 'unknown';
        $normalized = trim($normalized, '_');

        return $normalized !== '' ? $normalized : 'unknown';
    }

    /**
     * @param array<int, mixed> $configuredBuckets
     * @return float[]
     */
    private function resolveBuckets(array $configuredBuckets): array
    {
        $buckets = array_values(array_filter(array_map(
            static function ($value): ?float {
                if ($value === null || $value === '') {
                    return null;
                }

                $float = (float) $value;
                return $float > 0 ? $float : null;
            },
            $configuredBuckets
        )));

        if ($buckets === []) {
            return [0.05, 0.1, 0.25, 0.5, 1, 2, 5, 10];
        }

        sort($buckets);

        return $buckets;
    }
}

