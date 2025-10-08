<?php

declare(strict_types=1);

namespace App\Settings;

final class Settings
{
    /**
     * @var array<string, mixed>
     */
    private array $settings;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(array $settings)
    {
        $this->settings = $settings;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function getApp(): array
    {
        return $this->get('app', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLogger(): array
    {
        return $this->get('logger', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getDatabase(): array
    {
        return $this->get('database', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRedis(): array
    {
        return $this->get('redis', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRateLimit(): array
    {
        return $this->get('rate_limit', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCrm(): array
    {
        return $this->get('crm', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getCors(): array
    {
        return $this->get('cors', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getZapi(): array
    {
        return $this->get('zapi', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getMedia(): array
    {
        return $this->get('media', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOpenApi(): array
    {
        return $this->get('openapi', []);
    }

    public function getApiKey(): ?string
    {
        $app = $this->getApp();

        return $app['api_key'] ?? null;
    }
}

