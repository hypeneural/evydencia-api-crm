<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\Services\Concerns\HandlesListResults;
use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Infrastructure\Http\MediaStatusClient;
use App\Settings\Settings;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class OrderMediaStatusService
{
    use HandlesListResults;

    public const TARGET_PRODUCT_SLUG = 'natal';
    private const PER_PAGE = 200;
    private const MAX_FETCH_PAGES = 50;
    private const CRM_RETRIES = 2;

    private readonly string $crmOrdersEndpoint;
    private readonly string $galleryStatusUrl;
    private readonly string $gameStatusUrl;

    public function __construct(
        private readonly EvydenciaApiClient $crmClient,
        private readonly MediaStatusClient $mediaStatusClient,
        Settings $settings,
        private readonly LoggerInterface $logger
    ) {
        $crm = $settings->getCrm();
        $base = isset($crm['base_url']) ? (string) $crm['base_url'] : 'https://evydencia.com/api';
        $this->crmOrdersEndpoint = rtrim($base, '/') . '/orders/search';

        $media = $settings->getMedia();
        $status = is_array($media['status'] ?? null) ? $media['status'] : [];

        $this->galleryStatusUrl = $this->normalizeStatusUrl($status['gallery_url'] ?? 'https://galeria.fotosdenatal.com/status.php');
        $this->gameStatusUrl = $this->normalizeStatusUrl($status['game_url'] ?? 'https://game.fotosdenatal.com/status.php');
    }

    /**
     * @return array{
     *     data: array<int, array{
     *         id: int|string|null,
     *         uuid: string|null,
     *         schedule_1: string|null,
     *         status_name: string|null,
     *         product_name: string|null,
     *         in_gallery: bool,
     *         in_game: bool,
     *         customer: array{name:string|null, whatsapp:string|null}|null
     *     }>,
     *     summary: array<string, mixed>,
     *     media_status: array<string, mixed>
     * }
     *
     * @throws CrmUnavailableException
     * @throws CrmRequestException
     */
    public function getMediaStatus(string $sessionStart, string $sessionEnd, string $traceId, ?string $productSlug = null): array
    {
        $normalizedSlug = $this->normalizeProductSlug($productSlug);

        $this->logger->info('orders.media_status.collect.start', [
            'trace_id' => $traceId,
            'session_start' => $sessionStart,
            'session_end' => $sessionEnd,
            'requested_product_slug' => $productSlug,
            'normalized_product_slug' => $normalizedSlug,
        ]);

        $gameStatus = $this->mediaStatusClient->getStatus('game', $this->gameStatusUrl, $traceId);
        $galleryStatus = $this->mediaStatusClient->getStatus('gallery', $this->galleryStatusUrl, $traceId);

        $gameFolders = $gameStatus['folders'];
        $galleryFolders = $galleryStatus['folders'];

        try {
            $ordersResult = $this->collectOrders(
                $sessionStart,
                $sessionEnd,
                $traceId,
                $gameFolders,
                $galleryFolders,
                $normalizedSlug
            );

            $this->logger->info('orders.media_status.collect.success', [
                'trace_id' => $traceId,
                'session_start' => $sessionStart,
                'session_end' => $sessionEnd,
                'normalized_product_slug' => $normalizedSlug,
                'orders_returned' => count($ordersResult['data']),
                'skipped_canceled' => $ordersResult['skipped_canceled'],
            ]);

            return $this->finalizeResponse(
                $ordersResult['data'],
                $ordersResult['skipped_canceled'],
                $sessionStart,
                $sessionEnd,
                $galleryStatus,
                $gameStatus,
                null,
                $traceId,
                $normalizedSlug
            );
        } catch (CrmUnavailableException $exception) {
            $this->logger->warning('orders.media_status.crm_unavailable', [
                'trace_id' => $traceId,
                'session_start' => $sessionStart,
                'session_end' => $sessionEnd,
                'normalized_product_slug' => $normalizedSlug,
                'requested_product_slug' => $productSlug,
                'error' => $exception->getMessage(),
            ]);

            return $this->finalizeResponse(
                [],
                0,
                $sessionStart,
                $sessionEnd,
                $galleryStatus,
                $gameStatus,
                [
                    'code' => 'crm_unavailable',
                    'message' => 'CRM indisponivel. Dados de pedidos nao foram retornados.',
                ],
                $traceId,
                $normalizedSlug
            );
        } catch (CrmRequestException $exception) {
            $this->logger->warning('orders.media_status.crm_error', [
                'trace_id' => $traceId,
                'session_start' => $sessionStart,
                'session_end' => $sessionEnd,
                'normalized_product_slug' => $normalizedSlug,
                'requested_product_slug' => $productSlug,
                'status' => $exception->getStatusCode(),
                'error' => $exception->getMessage(),
            ]);

            return $this->finalizeResponse(
                [],
                0,
                $sessionStart,
                $sessionEnd,
                $galleryStatus,
                $gameStatus,
                [
                    'code' => 'crm_error',
                    'message' => 'CRM retornou erro ao buscar pedidos.',
                    'status' => $exception->getStatusCode(),
                ],
                $traceId,
                $normalizedSlug
            );
        } catch (Throwable $exception) {
            $this->logger->error('orders.media_status.failed', [
                'trace_id' => $traceId,
                'session_start' => $sessionStart,
                'session_end' => $sessionEnd,
                'normalized_product_slug' => $normalizedSlug,
                'requested_product_slug' => $productSlug,
                'error' => $exception->getMessage(),
            ]);

            $message = 'Ocorreu um erro inesperado ao coletar pedidos.';
            $code = 'unexpected_error';

            if (str_contains($exception->getMessage(), 'CRM token is not configured')) {
                $code = 'crm_token_missing';
                $message = 'CRM token nao configurado. Configure o CRM_TOKEN para habilitar a busca de pedidos.';
            }

            return $this->finalizeResponse(
                [],
                0,
                $sessionStart,
                $sessionEnd,
                $galleryStatus,
                $gameStatus,
                [
                    'code' => $code,
                    'message' => $message,
                ],
                $traceId,
                $normalizedSlug
            );
        }
    }

    /**
     * @param array<string, bool> $gameFolders
     * @param array<string, bool> $galleryFolders
     * @return array{
     *     data: array<int, array{
     *         id: int|string|null,
     *         schedule_1: string|null,
     *         status_name: string|null,
     *         product_name: string|null,
     *         in_gallery: bool,
     *         in_game: bool
     *     }>,
     *     skipped_canceled: int
     * }
     *
     * @throws CrmUnavailableException
     * @throws CrmRequestException
     */
    private function collectOrders(
        string $sessionStart,
        string $sessionEnd,
        string $traceId,
        array $gameFolders,
        array $galleryFolders,
        ?string $productSlug
    ): array {
        $page = 1;
        $skippedCanceled = 0;
        $orders = [];
        $pagesFetched = 0;

        $query = [
            'order[session-start]' => $sessionStart,
            'order[session-end]' => $sessionEnd,
            'per_page' => (string) self::PER_PAGE,
        ];

        if ($productSlug !== null && $productSlug !== '') {
            $query['product[slug]'] = $productSlug;
        }

        do {
            $query['page'] = (string) $page;

            $response = $this->performCrmRequest($query, $traceId);
            $body = $response['body'] ?? [];
            $data = $this->extractData(is_array($body) ? $body : []);
            $meta = $this->extractMeta(is_array($body) ? $body : []);
            $links = $this->extractLinks(is_array($body) ? $body : []);

            foreach ($data as $order) {
                if (!is_array($order)) {
                    continue;
                }

                if ($this->isCanceled($order)) {
                    $skippedCanceled++;
                    continue;
                }

                $orders[] = $this->mapOrder($order, $galleryFolders, $gameFolders);
            }

            $pagesFetched++;
            if ($pagesFetched >= self::MAX_FETCH_PAGES) {
                break;
            }

            $nextPage = $this->resolveNextPage($meta, $links, $page);
            if ($nextPage === null) {
                break;
            }

            $page = $nextPage;
        } while (true);

        return [
            'data' => $orders,
            'skipped_canceled' => $skippedCanceled,
        ];
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function resolveNextPage(array $meta, array $links, int $currentPage): ?int
    {
        $current = isset($meta['current_page']) ? (int) $meta['current_page'] : $currentPage;
        $last = $meta['last_page'] ?? $meta['total_pages'] ?? null;

        if (is_int($last) || is_string($last)) {
            $lastPage = (int) $last;
            if ($lastPage > $current) {
                return $current + 1;
            }

            return null;
        }

        $totalItems = $meta['total_items'] ?? $meta['total'] ?? null;
        if ((is_int($totalItems) || ctype_digit((string) $totalItems)) && $totalItems !== null) {
            $perPage = isset($meta['per_page']) ? (int) $meta['per_page'] : self::PER_PAGE;
            if ($perPage <= 0) {
                $perPage = self::PER_PAGE;
            }

            $totalPages = (int) ceil(((int) $totalItems) / $perPage);
            if ($totalPages > $current) {
                return $current + 1;
            }
        }

        $nextLink = $links['next'] ?? null;
        if (is_string($nextLink) && $nextLink !== '') {
            $nextQuery = $this->extractQueryFromLink($nextLink);
            if ($nextQuery !== null && isset($nextQuery['page'])) {
                $nextPage = (int) $nextQuery['page'];
                if ($nextPage > $currentPage) {
                    return $nextPage;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, bool> $galleryFolders
     * @param array<string, bool> $gameFolders
     * @return array{
     *     id: int|string|null,
     *     uuid: string|null,
     *     schedule_1: string|null,
     *     status_name: string|null,
     *     product_name: string|null,
     *     in_gallery: bool,
     *     in_game: bool,
     *     customer: array{name:string|null, whatsapp:string|null}|null
     * }
     */
    private function mapOrder(array $order, array $galleryFolders, array $gameFolders): array
    {
        $id = $order['id'] ?? null;
        $idString = $id === null ? null : (string) $id;
        $uuid = isset($order['uuid']) && is_string($order['uuid']) ? trim($order['uuid']) : null;

        $schedule = $order['schedule_1'] ?? ($order['schedule_one'] ?? null);
        $statusName = null;
        if (isset($order['status']) && is_array($order['status'])) {
            $statusName = isset($order['status']['name']) ? (string) $order['status']['name'] : null;
        }

        $productName = $this->resolveProductName($order['items'] ?? null);

        $inGallery = $idString !== null && isset($galleryFolders[$idString]);
        $inGame = $idString !== null && isset($gameFolders[$idString]);

        $customer = null;
        if (isset($order['customer']) && is_array($order['customer'])) {
            $customerName = isset($order['customer']['name']) ? $this->normalizeString($order['customer']['name']) : null;
            $customerWhatsapp = isset($order['customer']['whatsapp']) ? $this->normalizeString($order['customer']['whatsapp']) : null;

            if ($customerName !== null || $customerWhatsapp !== null) {
                $customer = [
                    'name' => $customerName,
                    'whatsapp' => $customerWhatsapp,
                ];
            }
        }

        return [
            'id' => $id,
            'uuid' => $uuid,
            'schedule_1' => is_string($schedule) ? $schedule : null,
            'status_name' => $statusName,
            'product_name' => $productName,
            'in_gallery' => $inGallery,
            'in_game' => $inGame,
            'customer' => $customer,
        ];
    }

    private function isCanceled(array $order): bool
    {
        $status = $order['status'] ?? null;
        if (!is_array($status)) {
            return false;
        }

        $statusId = isset($status['id']) ? (int) $status['id'] : null;
        if ($statusId === 1) {
            return true;
        }

        $name = isset($status['name']) ? mb_strtolower((string) $status['name'], 'UTF-8') : '';

        return trim($name) === 'pedido cancelado';
    }

    /**
     * @throws CrmUnavailableException
     * @throws CrmRequestException
     *
     * @return array{status:int, body:array<string, mixed>|array<int, mixed>, raw?:string}
     */
    private function performCrmRequest(array $query, string $traceId): array
    {
        $attempt = 0;

        while ($attempt <= self::CRM_RETRIES) {
            try {
                return $this->crmClient->searchOrders($query, $traceId);
            } catch (CrmUnavailableException $exception) {
                $attempt++;

                if ($attempt > self::CRM_RETRIES) {
                    throw $exception;
                }

                $this->logger->warning('orders.media_status.crm_retry', [
                    'trace_id' => $traceId,
                    'query' => $query,
                    'attempt' => $attempt,
                    'error' => $exception->getMessage(),
                ]);

                usleep(200000 * $attempt);
            }
        }

        throw new RuntimeException('CRM request failed after retries.');
    }

    /**
     * @param array<int, mixed>|null $items
     */
    private function resolveProductName(mixed $items): ?string
    {
        if (!is_array($items) || $items === []) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = $item['product'] ?? null;
            if (!is_array($product)) {
                continue;
            }

            $isBundle = isset($product['bundle']) && filter_var($product['bundle'], FILTER_VALIDATE_BOOL);
            $name = isset($product['name']) ? trim((string) $product['name']) : '';
            if ($isBundle && $name !== '') {
                return $name;
            }
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = $item['product'] ?? null;
            if (!is_array($product)) {
                continue;
            }

            $name = isset($product['name']) ? trim((string) $product['name']) : '';
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param array{
     *     folders: array<string, bool>,
     *     stats: array<string, mixed>,
     *     payload: array<string, mixed>
     * } $status
     * @return array<string, mixed>
     */
    private function finalizeResponse(
        array $orders,
        int $skippedCanceled,
        string $sessionStart,
        string $sessionEnd,
        array $galleryStatus,
        array $gameStatus,
        ?array $warning,
        string $traceId,
        ?string $productSlug
    ): array {
        $galleryTotals = $this->computeMediaTotals($galleryStatus);
        $gameTotals = $this->computeMediaTotals($gameStatus);

        $ordersCount = count($orders);
        $ordersWithoutGallery = 0;
        $ordersWithoutGame = 0;

        foreach ($orders as $order) {
            if (($order['in_gallery'] ?? false) === false) {
                $ordersWithoutGallery++;
            }

            if (($order['in_game'] ?? false) === false) {
                $ordersWithoutGame++;
            }
        }

        $ordersWithGallery = $ordersCount - $ordersWithoutGallery;
        $ordersWithGame = $ordersCount - $ordersWithoutGame;

        $summary = [
            'total_returned' => $ordersCount,
            'skipped_canceled' => $skippedCanceled,
            'session_window' => [$sessionStart, $sessionEnd],
            'sources' => [
                'orders' => $this->crmOrdersEndpoint,
                'game_status' => $this->gameStatusUrl,
                'gallery_status' => $this->galleryStatusUrl,
            ],
            'filters' => [
                'product_slug' => $productSlug,
                'default_product_slug' => self::TARGET_PRODUCT_SLUG,
            ],
            'kpis' => [
                'total_imagens' => $galleryTotals['total_photos'],
                'media_fotos' => $galleryTotals['media_por_pasta'],
                'total_galerias_ativas' => $galleryTotals['pastas_validas'],
                'total_jogos_ativos' => $gameTotals['pastas_validas'],
            ],
            'orders' => [
                'with_gallery' => $ordersWithGallery,
                'without_gallery' => $ordersWithoutGallery,
                'with_game' => $ordersWithGame,
                'without_game' => $ordersWithoutGame,
            ],
            'media' => [
                'gallery' => $this->buildMediaSummary($galleryTotals, $ordersWithGallery, $ordersWithoutGallery),
                'game' => $this->buildMediaSummary($gameTotals, $ordersWithGame, $ordersWithoutGame),
            ],
        ];

        if ($warning !== null) {
            $summary['warnings'] = [$warning];
        }

        return [
            'data' => $orders,
            'summary' => $summary,
            'media_status' => [
                'gallery' => $this->buildMediaSnapshot($galleryTotals, $this->galleryStatusUrl),
                'game' => $this->buildMediaSnapshot($gameTotals, $this->gameStatusUrl),
            ],
        ];
    }

    private function normalizeProductSlug(?string $slug): ?string
    {
        if ($slug === null) {
            return self::TARGET_PRODUCT_SLUG;
        }

        $trimmed = trim($slug);

        if ($trimmed === '') {
            return self::TARGET_PRODUCT_SLUG;
        }

        if ($trimmed === '*') {
            return null;
        }

        if (function_exists('mb_strtolower')) {
            return mb_strtolower($trimmed, 'UTF-8');
        }

        return strtolower($trimmed);
    }

    private function computeMediaTotals(array $status): array
    {
        $folders = $status['folders'];
        $stats = is_array($status['stats']) ? $status['stats'] : [];
        $payload = is_array($status['payload']) ? $status['payload'] : [];

        $pastasRaw = $payload['pastas'] ?? [];
        $pastas = [];
        if (is_array($pastasRaw)) {
            foreach ($pastasRaw as $item) {
                if (is_array($item)) {
                    $pastas[] = $item;
                }
            }
        }

        $totalPhotosCalculated = 0;
        $pastasSemArquivosCalc = 0;
        $pastasComArquivosCalc = 0;
        foreach ($pastas as $folder) {
            $total = $this->normalizeInt($folder['total_arquivos'] ?? null) ?? 0;
            if ($total > 0) {
                $pastasComArquivosCalc++;
            } else {
                $pastasSemArquivosCalc++;
            }

            $totalPhotosCalculated += $total;
        }

        $totalPhotos = $this->normalizeInt($stats['total_fotos'] ?? null);
        if ($totalPhotos === null) {
            $totalPhotos = $totalPhotosCalculated;
        }

        $pastasValidas = $this->normalizeInt($stats['pastas_validas'] ?? null);
        if ($pastasValidas === null) {
            $pastasValidas = $pastasComArquivosCalc;
        }

        $pastasSemArquivos = $this->normalizeInt($stats['pastas_sem_arquivos'] ?? null);
        if ($pastasSemArquivos === null) {
            $pastasSemArquivos = $pastasSemArquivosCalc;
        }

        $mediaPorPasta = $this->normalizeFloat($stats['media_por_pasta'] ?? null);
        if ($mediaPorPasta === null) {
            $mediaPorPasta = $pastasComArquivosCalc > 0 ? round($totalPhotosCalculated / $pastasComArquivosCalc, 2) : null;
        } else {
            $mediaPorPasta = round($mediaPorPasta, 2);
        }

        $averagePerFolder = $pastasValidas > 0
            ? round($totalPhotosCalculated / $pastasValidas, 2)
            : ($pastasComArquivosCalc > 0 ? round($totalPhotosCalculated / $pastasComArquivosCalc, 2) : null);

        $folderIds = array_map('strval', array_keys($folders));
        sort($folderIds, SORT_STRING);

        return [
            'total_photos' => $totalPhotos,
            'total_photos_calculated' => $totalPhotosCalculated,
            'pastas_validas' => $pastasValidas,
            'pastas_sem_arquivos' => $pastasSemArquivos,
            'media_por_pasta' => $mediaPorPasta,
            'average_per_folder' => $averagePerFolder,
            'folder_count' => count($folders),
            'folder_ids' => $folderIds,
            'stats' => $stats,
            'pastas' => $pastas,
            'base_url' => is_string($payload['base_url'] ?? null) ? $payload['base_url'] : null,
            'gerado_em' => is_string($payload['gerado_em'] ?? null) ? $payload['gerado_em'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function buildMediaSummary(array $totals, int $ordersWithMedia, int $ordersWithoutMedia): array
    {
        return [
            'total_photos' => $totals['total_photos'],
            'total_photos_calculated' => $totals['total_photos_calculated'],
            'total_images' => $totals['total_photos'],
            'media_por_pasta' => $totals['media_por_pasta'],
            'average_photos_per_folder' => $totals['average_per_folder'],
            'pastas_validas' => $totals['pastas_validas'],
            'pastas_sem_arquivos' => $totals['pastas_sem_arquivos'],
            'folder_count' => $totals['folder_count'],
            'orders_with_media' => $ordersWithMedia,
            'orders_without_media' => $ordersWithoutMedia,
            'folder_ids' => $totals['folder_ids'],
        ];
    }

    /**
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    private function buildMediaSnapshot(array $totals, string $sourceUrl): array
    {
        return [
            'source_url' => $sourceUrl,
            'base_url' => $totals['base_url'],
            'gerado_em' => $totals['gerado_em'],
            'stats' => $totals['stats'],
            'computed' => [
                'total_photos' => $totals['total_photos'],
                'total_photos_calculated' => $totals['total_photos_calculated'],
                'pastas_validas' => $totals['pastas_validas'],
                'pastas_sem_arquivos' => $totals['pastas_sem_arquivos'],
                'media_por_pasta' => $totals['media_por_pasta'],
                'average_por_pasta_calculada' => $totals['average_per_folder'],
                'folder_count' => $totals['folder_count'],
            ],
            'pastas' => $totals['pastas'],
            'folder_ids' => $totals['folder_ids'],
        ];
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '' && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function normalizeStatusUrl(string $url): string
    {
        $trimmed = trim($url);

        return $trimmed === '' ? $url : $trimmed;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
