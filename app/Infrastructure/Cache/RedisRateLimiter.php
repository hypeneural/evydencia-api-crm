<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

use App\Settings\Settings;
use Predis\Client as PredisClient;
use Predis\Exception\PredisException;

final class RedisRateLimiter
{
    private bool $enabled;

    public function __construct(private readonly ?PredisClient $client, Settings $settings)
    {
        $redis = $settings->getRedis();
        $this->enabled = (bool) ($redis['enabled'] ?? false) && $client !== null;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{allowed: bool, limit: int, remaining: int, reset: int|null}
     */
    public function hit(string $key, int $limit, int $windowSeconds): array
    {
        if (!$this->enabled || $limit <= 0) {
            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => $limit,
                'reset' => null,
            ];
        }

        try {
            $current = (int) $this->client->incr($key);
            if ($current === 1) {
                $this->client->expire($key, $windowSeconds);
            }
            $ttl = (int) $this->client->ttl($key);
            $remaining = max(0, $limit - $current);

            return [
                'allowed' => $current <= $limit,
                'limit' => $limit,
                'remaining' => $remaining,
                'reset' => $ttl > 0 ? time() + $ttl : null,
            ];
        } catch (PredisException) {
            return [
                'allowed' => true,
                'limit' => $limit,
                'remaining' => $limit,
                'reset' => null,
            ];
        }
    }
}
