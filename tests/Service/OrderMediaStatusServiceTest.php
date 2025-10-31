<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Application\Services\OrderMediaStatusService;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Infrastructure\Http\MediaStatusClient;
use App\Settings\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OrderMediaStatusServiceTest extends TestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        MediaStatusClient::clearLocalCache();

        $this->settings = new Settings([
            'crm' => [
                'base_url' => 'https://crm.example/api',
                'token' => 'token',
                'timeout' => 5,
            ],
            'media' => [
                'status' => [
                    'gallery_url' => 'https://galeria.example/status.php',
                    'game_url' => 'https://game.example/status.php',
                    'cache_ttl' => 30,
                    'timeout' => 5,
                    'retries' => 0,
                ],
            ],
        ]);
    }

    public function testReturnsWarningWhenCrmTokenMissing(): void
    {
        $settings = new Settings([
            'crm' => [
                'base_url' => 'https://crm.example/api',
                'token' => '',
                'timeout' => 5,
            ],
            'media' => [
                'status' => [
                    'gallery_url' => 'https://galeria.example/status.php',
                    'game_url' => 'https://game.example/status.php',
                    'cache_ttl' => 30,
                    'timeout' => 5,
                    'retries' => 0,
                ],
            ],
        ]);

        $service = $this->createService(
            [
                $this->crmResponse(['error' => 'token missing'], 401),
            ],
            [
                $this->mediaResponse(['pastas' => []]),
                $this->mediaResponse(['pastas' => []]),
            ],
            $history,
            $settings
        );

        $result = $service->getMediaStatus('2025-09-01', '2025-09-30', 'trace123');

        self::assertSame(0, $result['summary']['total_returned']);
        self::assertSame(0, $result['summary']['orders']['with_gallery']);
        self::assertSame(0, $result['summary']['orders']['with_game']);
        self::assertArrayHasKey('warnings', $result['summary']);
        self::assertSame('crm_token_missing', $result['summary']['warnings'][0]['code']);
        self::assertSame(0, $result['summary']['kpis']['total_imagens']);
        self::assertNull($result['summary']['kpis']['media_fotos']);
    }

    public function testCollectsOrdersWithMediaFlags(): void
    {
        $history = [];
        $service = $this->createService(
            [
                $this->crmResponse([
                    'data' => [
                        [
                            'id' => 4490,
                            'schedule_1' => '2025-10-28 17:00:00',
                            'status' => ['id' => 2, 'name' => 'Aguardando Retirar'],
                            'items' => [
                                ['product' => ['bundle' => true, 'name' => 'Experiencia Ho-Ho-Ho']],
                            ],
                        ],
                        [
                            'id' => 4491,
                            'schedule_1' => '2025-10-28 18:00:00',
                            'status' => ['id' => 1, 'name' => 'Pedido Cancelado'],
                            'items' => [
                                ['product' => ['name' => 'Experiencia Noel']],
                            ],
                        ],
                    ],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 2,
                    ],
                ]),
                $this->crmResponse([
                    'data' => [
                        [
                            'id' => '4494',
                            'schedule_1' => '2025-10-31 10:00:00',
                            'status' => ['id' => 3, 'name' => 'Sessao Agendada'],
                            'items' => [
                                ['product' => ['bundle' => false, 'name' => 'Pacote Kids']],
                                ['product' => ['bundle' => true, 'name' => 'Pacote Premium']],
                            ],
                        ],
                    ],
                    'meta' => [
                        'current_page' => 2,
                        'last_page' => 2,
                    ],
                ]),
            ],
            [
                $this->mediaResponse([
                    'base_url' => 'https://game.example',
                    'gerado_em' => '2025-10-30T00:00:00+00:00',
                    'stats' => [
                        'pastas_validas' => 1,
                        'pastas_sem_arquivos' => 0,
                        'total_fotos' => 16,
                        'media_por_pasta' => 16,
                    ],
                    'pastas' => [
                        ['pasta' => '4490', 'total_arquivos' => 16],
                    ],
                ]),
                $this->mediaResponse([
                    'base_url' => 'https://galeria.example',
                    'gerado_em' => '2025-10-30T00:00:00+00:00',
                    'stats' => [
                        'pastas_validas' => 1,
                        'pastas_sem_arquivos' => 0,
                        'total_fotos' => 12,
                        'media_por_pasta' => 12,
                    ],
                    'pastas' => [
                        ['pasta' => '4494', 'total_arquivos' => 12],
                    ],
                ]),
            ],
            $history
        );

        $result = $service->getMediaStatus('2025-09-01', '2025-10-31', 'trace123');

        self::assertCount(2, $result['data']);
        $first = $result['data'][0];
        self::assertSame(4490, $first['id']);
        self::assertTrue($first['in_game']);
        self::assertFalse($first['in_gallery']);
        self::assertSame('Experiencia Ho-Ho-Ho', $first['product_name']);

        $second = $result['data'][1];
        self::assertSame('4494', $second['id']);
        self::assertTrue($second['in_gallery']);
        self::assertFalse($second['in_game']);
        self::assertSame('Pacote Premium', $second['product_name']);

        $summary = $result['summary'];
        self::assertSame(2, $summary['total_returned']);
        self::assertSame(1, $summary['skipped_canceled']);
        self::assertSame(['2025-09-01', '2025-10-31'], $summary['session_window']);
        self::assertSame('https://crm.example/api/orders/search', $summary['sources']['orders']);
        self::assertSame('natal', $summary['filters']['product_slug']);
        self::assertSame('natal', $summary['filters']['default_product_slug']);
        self::assertSame(1, $summary['orders']['with_gallery']);
        self::assertSame(1, $summary['orders']['without_gallery']);
        self::assertSame(1, $summary['orders']['with_game']);
        self::assertSame(1, $summary['orders']['without_game']);
        self::assertSame(12, $summary['kpis']['total_imagens']);
        self::assertEqualsWithDelta(12.0, $summary['kpis']['media_fotos'], 0.0001);
        self::assertSame(1, $summary['kpis']['total_galerias_ativas']);
        self::assertSame(1, $summary['kpis']['total_jogos_ativos']);

        $gallerySummary = $summary['media']['gallery'];
        self::assertSame(12, $gallerySummary['total_photos']);
        self::assertSame(12, $gallerySummary['total_photos_calculated']);
        self::assertSame(1, $gallerySummary['orders_with_media']);
        self::assertSame(1, $gallerySummary['orders_without_media']);

        $mediaStatus = $result['media_status'];
        self::assertSame('https://galeria.example', $mediaStatus['gallery']['base_url']);
        self::assertSame(['4494'], $mediaStatus['gallery']['folder_ids']);
        self::assertSame(12, $mediaStatus['gallery']['computed']['total_photos']);
        self::assertSame(['4490'], $mediaStatus['game']['folder_ids']);
        self::assertSame(16, $mediaStatus['game']['computed']['total_photos']);

        self::assertCount(2, $history);
        $firstRequest = $history[0]['request'];
        parse_str($firstRequest->getUri()->getQuery(), $query);
        self::assertSame('2025-09-01', $query['order']['session-start']);
        self::assertSame('natal', $query['product']['slug']);
        self::assertSame('1', $query['page']);
    }

    public function testAllowsOverridingProductSlug(): void
    {
        $history = [];
        $service = $this->createService(
            [
                $this->crmResponse([
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1],
                ]),
            ],
            [
                $this->mediaResponse(['pastas' => []]),
                $this->mediaResponse(['pastas' => []]),
            ],
            $history
        );

        $result = $service->getMediaStatus('2025-09-01', '2025-09-30', 'trace123', 'Promo-Pack');

        self::assertNotEmpty($history);
        $firstRequest = $history[0]['request'];
        parse_str($firstRequest->getUri()->getQuery(), $query);
        self::assertSame('promo-pack', $query['product']['slug']);

        $filters = $result['summary']['filters'];
        self::assertSame('promo-pack', $filters['product_slug']);
        self::assertSame('natal', $filters['default_product_slug']);
    }

    public function testWildcardProductSlugDisablesFilter(): void
    {
        $history = [];
        $service = $this->createService(
            [
                $this->crmResponse([
                    'data' => [],
                    'meta' => ['current_page' => 1, 'last_page' => 1],
                ]),
            ],
            [
                $this->mediaResponse(['pastas' => []]),
                $this->mediaResponse(['pastas' => []]),
            ],
            $history
        );

        $result = $service->getMediaStatus('2025-09-01', '2025-09-30', 'trace123', '*');

        self::assertNotEmpty($history);
        $firstRequest = $history[0]['request'];
        parse_str($firstRequest->getUri()->getQuery(), $query);
        self::assertArrayNotHasKey('product', $query);

        $filters = $result['summary']['filters'];
        self::assertNull($filters['product_slug']);
        self::assertSame('natal', $filters['default_product_slug']);
    }

    public function testRetriesCrmWhenUnavailableAndEventuallySucceeds(): void
    {
        $history = [];
        $service = $this->createService(
            [
                new ConnectException('timeout', new Request('GET', 'orders/search')),
                $this->crmResponse([
                    'data' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                    ],
                ]),
            ],
            [
                $this->mediaResponse(['pastas' => []]),
                $this->mediaResponse(['pastas' => []]),
            ],
            $history
        );

        $result = $service->getMediaStatus('2025-09-01', '2025-09-30', 'trace123');

        self::assertSame(0, $result['summary']['total_returned']);
        self::assertSame(0, $result['summary']['skipped_canceled']);
        self::assertArrayHasKey('media_status', $result);
        self::assertGreaterThanOrEqual(1, count($history));
    }

    /**
     * @param array<int, Response|\Throwable> $crmQueue
     * @param array<int, Response> $mediaQueue
     * @param array<int, array<string, mixed>> $history
     */
    private function createService(array $crmQueue, array $mediaQueue, ?array &$history = null, ?Settings $settings = null): OrderMediaStatusService
    {
        $settings = $settings ?? $this->settings;

        $crmMock = new MockHandler($crmQueue);
        $history = [];
        $crmStack = HandlerStack::create($crmMock);
        $crmStack->push(Middleware::history($history));
        $crmHttp = new Client([
            'handler' => $crmStack,
            'base_uri' => 'https://crm.example/api/',
            'http_errors' => false,
            'timeout' => 5,
        ]);
        $crmClient = new EvydenciaApiClient($crmHttp, $settings, new NullLogger());

        $mediaMock = new MockHandler($mediaQueue);
        $mediaStack = HandlerStack::create($mediaMock);
        $mediaHttp = new Client([
            'handler' => $mediaStack,
            'http_errors' => false,
            'timeout' => 5,
        ]);
        $mediaClient = new MediaStatusClient(null, new NullLogger(), 5.0, 0, 30, $mediaHttp);

        return new OrderMediaStatusService($crmClient, $mediaClient, $settings, new NullLogger());
    }

    private function crmResponse(array $payload, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function mediaResponse(array $payload, int $status = 200): Response
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
