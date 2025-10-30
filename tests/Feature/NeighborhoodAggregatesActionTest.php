<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Schools\ListNeighborhoodAggregatesAction;
use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use App\Settings\Settings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tests\Doubles\StubSchoolRepository;
use Tests\Fixtures\AggregatesFixtures;

final class NeighborhoodAggregatesActionTest extends TestCase
{
    private StubSchoolRepository $repository;

    private SchoolService $service;

    private ApiResponder $responder;

    private ListNeighborhoodAggregatesAction $action;

    protected function setUp(): void
    {
        $this->repository = new StubSchoolRepository();
        $this->service = new SchoolService($this->repository, new NullLogger());
        $this->responder = new ApiResponder(new Settings([]));
        $this->action = new ListNeighborhoodAggregatesAction($this->service, $this->responder);
    }

    public function testReturnsAggregatesWithFiltersAndEtag(): void
    {
        $data = AggregatesFixtures::neighborhoodWithoutSchools();
        $this->repository->neighborhoodAggregatesByCity[5] = $data;
        $expectedEtag = $this->expectedEtag(
            $data,
            ['status' => 'pendente', 'search' => 'areias'],
            'neighborhood-aggregates:5',
            true
        );

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/cidades/5/bairros')
            ->withQueryParams([
                'withEscolas' => 'true',
                'status' => 'pendente',
                'search' => 'areias',
            ]);

        $response = (new ResponseFactory())->createResponse();

        $result = $this->action->__invoke($request, $response, ['cidadeId' => 5]);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame('"' . $expectedEtag . '"', $result->getHeaderLine('ETag'));
        self::assertSame('private, max-age=30, must-revalidate', $result->getHeaderLine('Cache-Control'));

        $decoded = json_decode((string) $result->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($decoded['success']);
        self::assertSame($expectedEtag, $decoded['meta']['etag']);
        self::assertSame(5, $decoded['meta']['cidade_id']);
        self::assertSame(['status' => 'pendente', 'search' => 'areias'], $this->repository->lastNeighborhoodAggregateFilters);
        self::assertTrue($this->repository->lastIncludeNeighborhoodSchools);
    }

    public function testReturns304WhenEtagMatches(): void
    {
        $data = AggregatesFixtures::neighborhoodWithoutSchools();
        $this->repository->neighborhoodAggregatesByCity[5] = $data;
        $expectedEtag = $this->expectedEtag($data, [], 'neighborhood-aggregates:5', false);

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/v1/cidades/5/bairros')
            ->withHeader('If-None-Match', '"' . $expectedEtag . '"');

        $response = (new ResponseFactory())->createResponse();

        $result = $this->action->__invoke($request, $response, ['cidadeId' => 5]);

        self::assertSame(304, $result->getStatusCode());
        self::assertSame('"' . $expectedEtag . '"', $result->getHeaderLine('ETag'));
        self::assertSame('', (string) $result->getBody());
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

            if ($includeNested && isset($current['escolas']) && is_array($current['escolas'])) {
                foreach ($current['escolas'] as $child) {
                    $stack[] = $child;
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
