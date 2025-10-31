<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Orders\GetOrderMediaStatusAction;
use App\Application\Services\OrderMediaStatusService;
use App\Application\Support\ApiResponder;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Infrastructure\Http\MediaStatusClient;
use App\Settings\Settings;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OrderMediaStatusActionTest extends TestCase
{
    private Settings $settings;

    protected function setUp(): void
    {
        MediaStatusClient::clearLocalCache();

        $this->settings = new Settings([
            'crm' => [
                'base_url' => 'https://crm.example/api',
                'token' => 'token',
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

    public function testReturnsMediaStatusPayload(): void
    {
        $action = $this->createAction(
            [
                $this->crmResponse([
                    'data' => [
                        [
                            'id' => 4490,
                            'uuid' => '6cc28bfa-e8b7-4868-a7da-4743ab164482',
                            'schedule_1' => '2025-10-28 17:00:00',
                            'status' => ['id' => 2, 'name' => 'Aguardando Retirar'],
                            'items' => [
                                ['product' => ['bundle' => true, 'name' => 'Experiencia Ho-Ho-Ho']],
                            ],
                            'customer' => [
                                'name' => 'Mara Rubia Soares Gomes',
                                'whatsapp' => '48996048606',
                            ],
                        ],
                    ],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
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
                        ['pasta' => '4490', 'total_arquivos' => 12],
                    ],
                ]),
            ]
        );

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $sessionStart = GetOrderMediaStatusAction::DEFAULT_SESSION_START;

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/orders/media-status')
            ->withAttribute('trace_id', 'trace-1');
        $response = (new ResponseFactory())->createResponse();

        $result = $action->__invoke($request, $response);

        $payload = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(200, $result->getStatusCode(), 'Payload: ' . json_encode($payload));
        self::assertTrue($payload['success']);
        self::assertSame(1, $payload['summary']['total_returned']);
        self::assertSame(0, $payload['summary']['orders']['without_gallery'], 'Orders summary: ' . json_encode($payload['summary']['orders']));
        self::assertSame(0, $payload['summary']['orders']['without_game'], 'Orders summary: ' . json_encode($payload['summary']['orders']));
        self::assertSame(12, $payload['summary']['kpis']['total_imagens']);
        self::assertEqualsWithDelta(12.0, $payload['summary']['kpis']['media_fotos'], 0.0001);
        self::assertSame(1, $payload['summary']['kpis']['total_galerias_ativas']);
        self::assertSame(1, $payload['summary']['kpis']['total_jogos_ativos']);
        self::assertSame(12, $payload['summary']['media']['gallery']['total_photos']);
        self::assertSame(16, $payload['summary']['media']['game']['total_photos']);
        self::assertArrayHasKey('media_status', $payload);
        self::assertSame(['4490'], $payload['media_status']['gallery']['folder_ids']);
        self::assertSame(12, $payload['media_status']['gallery']['computed']['total_photos']);
        self::assertTrue($payload['data'][0]['in_gallery']);
        self::assertSame('6cc28bfa-e8b7-4868-a7da-4743ab164482', $payload['data'][0]['uuid']);
        self::assertSame('Mara Rubia Soares Gomes', $payload['data'][0]['customer']['name']);
        self::assertSame('48996048606', $payload['data'][0]['customer']['whatsapp']);
        self::assertSame('natal', $payload['meta']['filters']['product_slug']);
        self::assertSame('natal', $payload['meta']['filters']['default_product_slug']);
        self::assertSame('natal', $payload['meta']['filters']['requested_product_slug']);
        self::assertSame($sessionStart, $payload['meta']['filters']['session_start']);
        self::assertSame($today, $payload['meta']['filters']['session_end']);
        self::assertSame('trace-1', $payload['trace_id']);
    }

    /**
     * @param array<int, Response> $crmQueue
     * @param array<int, Response> $mediaQueue
     */
    private function createAction(array $crmQueue, array $mediaQueue): GetOrderMediaStatusAction
    {
        $crmMock = new MockHandler($crmQueue ?: [$this->crmResponse(['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1]])]);
        $crmStack = HandlerStack::create($crmMock);
        $crmHttp = new Client([
            'handler' => $crmStack,
            'base_uri' => 'https://crm.example/api/',
            'http_errors' => false,
        ]);
        $crmClient = new EvydenciaApiClient($crmHttp, $this->settings, new NullLogger());

        $mediaMock = new MockHandler($mediaQueue ?: [
            $this->mediaResponse(['pastas' => []]),
            $this->mediaResponse(['pastas' => []]),
        ]);
        $mediaStack = HandlerStack::create($mediaMock);
        $mediaHttp = new Client([
            'handler' => $mediaStack,
            'http_errors' => false,
        ]);
        $mediaClient = new MediaStatusClient(null, new NullLogger(), 5.0, 0, 30, $mediaHttp);

        $service = new OrderMediaStatusService($crmClient, $mediaClient, $this->settings, new NullLogger());
        $responder = new ApiResponder(new Settings([]));

        return new GetOrderMediaStatusAction($service, $responder, new NullLogger());
    }

    private function crmResponse(array $payload): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function mediaResponse(array $payload): Response
    {
        return new Response(
            200,
            ['Content-Type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
