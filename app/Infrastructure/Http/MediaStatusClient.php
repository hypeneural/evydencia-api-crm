<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Predis\Client as PredisClient;
use Psr\Log\LoggerInterface;

final class MediaStatusClient
{
    private const CACHE_PREFIX = 'media_status:';

    /**
     * @var array<string, array{expires_at:int, data:array<string, mixed>}>
     */
    private static array $localCache = [];

    private Client $httpClient;

    public function __construct(
        private readonly ?PredisClient $cache,
        private readonly LoggerInterface $logger,
        private readonly float $timeout,
        private readonly int $retries,
        private readonly int $cacheTtl,
        ?Client $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new Client([
            'timeout' => $this->timeout,
            'http_errors' => false,
        ]);
    }

    public static function clearLocalCache(): void
    {
        self::$localCache = [];
    }

    /**
     * @return array{
     *     folders: array<string, bool>,
     *     stats: array<string, mixed>,
     *     payload: array<string, mixed>
     * }
     */
    public function getStatus(string $sourceKey, string $url, string $traceId): array
    {
        $cacheKey = self::CACHE_PREFIX . $sourceKey;
        $cached = $this->getFromCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $payload = $this->fetchWithRetry($url, $traceId, $sourceKey);

        return $this->storeResult($cacheKey, [
            'folder_ids' => $this->extractFolderIds($payload),
            'stats' => $this->extractStats($payload),
            'payload' => is_array($payload) ? $payload : [],
        ]);
    }

    /**
     * @return array{
     *     folders: array<string, bool>,
     *     stats: array<string, mixed>,
     *     payload: array<string, mixed>
     * }|null
     */
    private function getFromCache(string $cacheKey): ?array
    {
        $now = time();

        if (isset(self::$localCache[$cacheKey])) {
            $entry = self::$localCache[$cacheKey];
            if ($entry['expires_at'] > $now) {
                return $this->inflateResult($entry['data']);
            }

            unset(self::$localCache[$cacheKey]);
        }

        if ($this->cache === null) {
            return null;
        }

        $cached = $this->cache->get($cacheKey);
        if ($cached === null) {
            return null;
        }

        $decoded = json_decode($cached, true);
        if (!is_array($decoded)) {
            return null;
        }

        $normalized = [
            'folder_ids' => $this->normalizeIds($decoded['folder_ids'] ?? []),
            'stats' => is_array($decoded['stats'] ?? null) ? $decoded['stats'] : [],
            'payload' => is_array($decoded['payload'] ?? null) ? $decoded['payload'] : [],
        ];

        self::$localCache[$cacheKey] = [
            'expires_at' => $now + $this->cacheTtl,
            'data' => $normalized,
        ];

        return $this->inflateResult($normalized);
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     folders: array<string, bool>,
     *     stats: array<string, mixed>,
     *     payload: array<string, mixed>
     * }
     */
    private function inflateResult(array $data): array
    {
        $folderIds = $this->normalizeIds($data['folder_ids'] ?? []);

        return [
            'folders' => $folderIds === [] ? [] : array_fill_keys($folderIds, true),
            'stats' => is_array($data['stats'] ?? null) ? $data['stats'] : [],
            'payload' => is_array($data['payload'] ?? null) ? $data['payload'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     folders: array<string, bool>,
     *     stats: array<string, mixed>,
     *     payload: array<string, mixed>
     * }
     */
    private function storeResult(string $cacheKey, array $data): array
    {
        $normalized = [
            'folder_ids' => $this->normalizeIds($data['folder_ids'] ?? []),
            'stats' => is_array($data['stats'] ?? null) ? $data['stats'] : [],
            'payload' => is_array($data['payload'] ?? null) ? $data['payload'] : [],
        ];

        self::$localCache[$cacheKey] = [
            'expires_at' => time() + $this->cacheTtl,
            'data' => $normalized,
        ];

        if ($this->cache !== null) {
            $payload = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($payload !== false) {
                $this->cache->setex($cacheKey, $this->cacheTtl, $payload);
            }
        }

        return $this->inflateResult($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeIds(array $ids): array
    {
        $unique = [];
        foreach ($ids as $id) {
            $value = trim((string) $id);
            if ($value === '') {
                continue;
            }

            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchWithRetry(string $url, string $traceId, string $sourceKey): ?array
    {
        $attempt = 0;
        $maxAttempts = max(1, $this->retries + 1);
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => $this->timeout,
                ]);

                $status = $response->getStatusCode();
                $body = (string) $response->getBody();

                if ($status >= 200 && $status < 300) {
                    if ($body === '') {
                        return [];
                    }

                    $decoded = json_decode($body, true);
                    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                        $this->logger->warning('media_status.invalid_json', [
                            'trace_id' => $traceId,
                            'source' => $sourceKey,
                            'status' => $status,
                        ]);

                        return [];
                    }

                    return is_array($decoded) ? $decoded : [];
                }

                $this->logger->warning('media_status.unexpected_status', [
                    'trace_id' => $traceId,
                    'source' => $sourceKey,
                    'status' => $status,
                ]);

                if ($status < 500 || $attempt + 1 >= $maxAttempts) {
                    return [];
                }
            } catch (GuzzleException $exception) {
                $lastException = $exception;
                $this->logger->warning('media_status.request_failed', [
                    'trace_id' => $traceId,
                    'source' => $sourceKey,
                    'attempt' => $attempt + 1,
                    'error' => $exception->getMessage(),
                ]);

                if ($attempt + 1 >= $maxAttempts) {
                    break;
                }
            }

            $attempt++;
            usleep(150000 * $attempt);
        }

        if ($lastException !== null) {
            $this->logger->error('media_status.gave_up', [
                'trace_id' => $traceId,
                'source' => $sourceKey,
                'error' => $lastException->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function extractFolderIds(mixed $payload): array
    {
        if (!is_array($payload) || $payload === []) {
            return [];
        }

        $pastas = $payload['pastas'] ?? null;
        if (!is_array($pastas)) {
            return [];
        }

        $unique = [];
        foreach ($pastas as $folder) {
            if (!is_array($folder)) {
                continue;
            }

            $value = isset($folder['pasta']) ? trim((string) $folder['pasta']) : '';
            if ($value === '') {
                continue;
            }

            $unique[$value] = true;
        }

        return array_keys($unique);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractStats(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $stats = $payload['stats'] ?? [];

        return is_array($stats) ? $stats : [];
    }
}
