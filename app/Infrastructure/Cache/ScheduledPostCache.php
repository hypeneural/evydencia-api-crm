<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Predis\Client as PredisClient;

final class ScheduledPostCache
{
    private const VERSION_KEY = 'scheduled_posts:version';
    private const TTL_SECONDS = 60;

    public function __construct(private readonly ?PredisClient $client)
    {
    }

    /**
     * @param array<string, mixed> $signature
     * @return array<string, mixed>|null
     */
    public function get(array $signature): ?array
    {
        if ($this->client === null) {
            return null;
        }

        $key = $this->buildCacheKey($signature);
        if ($key === null) {
            return null;
        }

        $cached = $this->client->get($key);
        if ($cached === null) {
            return null;
        }

        $decoded = json_decode($cached, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $signature
     * @param array<string, mixed> $payload
     */
    public function set(array $signature, array $payload, string $etag, int $ttl = self::TTL_SECONDS): void
    {
        if ($this->client === null) {
            return;
        }

        $key = $this->buildCacheKey($signature);
        if ($key === null) {
            return;
        }

        $data = json_encode([
            'etag' => $etag,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($data === false) {
            return;
        }

        $this->client->setex($key, $ttl, $data);
    }

    public function invalidate(): void
    {
        if ($this->client === null) {
            return;
        }

        $this->client->incr(self::VERSION_KEY);
    }

    /**
     * @param array<string, mixed> $signature
     */
    private function buildCacheKey(array $signature): ?string
    {
        if ($this->client === null) {
            return null;
        }

        $version = $this->resolveVersion();
        ksort($signature);
        $hash = sha1(json_encode($signature));

        return sprintf('scheduled_posts:list:%s:%s', $version, $hash);
    }

    private function resolveVersion(): string
    {
        if ($this->client === null) {
            return '1';
        }

        $version = $this->client->get(self::VERSION_KEY);
        if ($version === null) {
            $this->client->set(self::VERSION_KEY, '1');
            return '1';
        }

        return (string) $version;
    }
}
