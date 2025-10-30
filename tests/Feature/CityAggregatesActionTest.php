<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Schools\ListCityAggregatesAction;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Doubles\StubSchoolRepository;
use Tests\Fixtures\AggregatesFixtures;

final class CityAggregatesActionTest extends TestCase
{
    private StubSchoolRepository $repository;

    private SchoolService $service;

    private ApiResponder $responder;

    private ListCityAggregatesAction $action;

    protected function setUp(): void
    {
        $this->repository = new StubSchoolRepository();
        $this->service = new SchoolService($this->repository, new NullLogger());
        $this->responder = new ApiResponder(new Settings([]));
        $this->action = new ListCityAggregatesAction($this->service, $this->responder);
    }

    public function testReturnsEtagAndCacheControlForWebClient(): void
    {
        $data = AggregatesFixtures::cityWithoutNeighborhoods();
        $this->repository->cityAggregates = $data;
        $expectedEtag = $this->expectedEtag($data, ['status' => 'todos'], 'city-aggregates', true);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory
            ->createServerRequest('GET', '/v1/cidades')
            ->withQueryParams([
                'includeBairros' => 'true',
                'status' => 'todos',
            ]);

        $response = (new ResponseFactory())->createResponse();

        $result = $this->action->__invoke($request, $response);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('"' . $expectedEtag . '"', $result->getHeaderLine('ETag'));
        self::assertSame('private, max-age=30, must-revalidate', $result->getHeaderLine('Cache-Control'));

        $body = (string) $result->getBody();
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($decoded['success']);
        self::assertSame($expectedEtag, $decoded['meta']['etag']);
        self::assertSame(['status' => 'todos'], $this->repository->lastCityAggregateFilters);
    }

    public function testReturns304WhenEtagMatches(): void
    {
        $data = AggregatesFixtures::cityWithoutNeighborhoods();
        $this->repository->cityAggregates = $data;
        $expectedEtag = $this->expectedEtag($data, [], 'city-aggregates', false);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory
            ->createServerRequest('GET', '/v1/cidades')
            ->withHeader('If-None-Match', '"' . $expectedEtag . '"');

        $response = (new ResponseFactory())->createResponse();

        $result = $this->action->__invoke($request, $response);

        self::assertSame(304, $result->getStatusCode());
        self::assertSame('"' . $expectedEtag . '"', $result->getHeaderLine('ETag'));
        self::assertSame('private, max-age=30, must-revalidate', $result->getHeaderLine('Cache-Control'));
        self::assertSame('', (string) $result->getBody());
    }

    public function testAppliesMobileCacheControlWhenHeaderProvided(): void
    {
        $data = AggregatesFixtures::cityWithoutNeighborhoods();
        $this->repository->cityAggregates = $data;
        $expectedEtag = $this->expectedEtag($data, [], 'city-aggregates', false);

        $requestFactory = new ServerRequestFactory();
        $request = $requestFactory
            ->createServerRequest('GET', '/v1/cidades')
            ->withHeader('X-Client-Type', 'mobile');

        $response = (new ResponseFactory())->createResponse();

        $result = $this->action->__invoke($request, $response);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('"' . $expectedEtag . '"', $result->getHeaderLine('ETag'));
        self::assertSame('private, max-age=120, stale-while-revalidate=300', $result->getHeaderLine('Cache-Control'));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $filters
     */
    private function expectedEtag(array $items, array $filters, string $scope, bool $includeNested): string
    {
        $stack = $items;
        $maxVersion = 0;
        $latestTimestamp = null;

        while ($stack !== []) {
            $current = array_pop($stack);
            if (!is_array($current)) {
                continue;
            }

            if (isset($current['versao_row'])) {
                $maxVersion = max($maxVersion, (int) $current['versao_row']);
            }

            if (isset($current['atualizado_em']) && is_string($current['atualizado_em']) && $current['atualizado_em'] !== '') {
                $timestamp = strtotime($current['atualizado_em']);
                if ($timestamp !== false) {
                    $latestTimestamp = $latestTimestamp === null ? $timestamp : max($latestTimestamp, $timestamp);
                }
            }

            if ($includeNested) {
                foreach (['bairros', 'escolas'] as $childKey) {
                    if (isset($current[$childKey]) && is_array($current[$childKey])) {
                        foreach ($current[$childKey] as $child) {
                            $stack[] = $child;
                        }
                    }
                }
            }
        }

        $payload = [
            'scope' => $scope,
            'filters' => $filters,
            'version' => $maxVersion,
            'updated' => $latestTimestamp !== null ? gmdate('Y-m-d\TH:i:s\Z', $latestTimestamp) : null,
        ];

        return sha1(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
