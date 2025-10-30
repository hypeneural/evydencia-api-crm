<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Application\Services\SchoolService;
use App\Domain\Repositories\SchoolRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\AggregatesFixtures;

final class SchoolServiceAggregatesTest extends TestCase
{
    /** @var SchoolRepositoryInterface&MockObject */
    private SchoolRepositoryInterface $repository;

    private SchoolService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SchoolRepositoryInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $this->service = new SchoolService($this->repository, $logger);
    }

    public function testListCityAggregatesWithoutNeighborhoods(): void
    {
        $payload = [
            [
                'id' => 1,
                'nome' => 'Tijucas',
                'sigla_uf' => 'SC',
                'totais' => [
                    'total_escolas' => 10,
                    'total_bairros' => 5,
                    'total_alunos' => 1200,
                    'panfletagem_feita' => 6,
                    'panfletagem_pendente' => 4,
                ],
                'periodos' => [
                    'Matutino' => 8,
                    'Vespertino' => 7,
                    'Noturno' => 2,
                ],
                'etapas' => [
                    'bercario_0a1' => 10,
                    'bercario_1a2' => 11,
                    'maternal_2a3' => 12,
                    'jardim_3a4' => 13,
                    'preI_4a5' => 14,
                    'preII_5a6' => 15,
                    'ano1_6a7' => 16,
                    'ano2_7a8' => 17,
                    'ano3_8a9' => 18,
                    'ano4_9a10' => 19,
                ],
                'atualizado_em' => '2025-01-05T12:00:00Z',
            ],
        ];

        $this->repository
            ->expects(self::once())
            ->method('listCityAggregates')
            ->with(['status' => 'pendente'], false)
            ->willReturn($payload);

        $result = $this->service->listCityAggregates(['status' => 'pendente'], false);

        self::assertSame($payload, $result);
    }

    public function testListNeighborhoodAggregatesConvertsSchoolsWhenRequested(): void
    {
        $rawSchool = $this->sampleSchoolRow();
        $repositoryResponse = [
            [
                'id' => 20,
                'nome' => 'Centro',
                'cidade_id' => 1,
                'totais' => [
                    'total_escolas' => 1,
                    'total_alunos' => 300,
                    'panfletagem_feita' => 1,
                    'panfletagem_pendente' => 0,
                ],
                'periodos' => [
                    'Matutino' => 1,
                    'Vespertino' => 0,
                    'Noturno' => 0,
                ],
                'etapas' => [
                    'bercario_0a1' => 0,
                    'bercario_1a2' => 0,
                    'maternal_2a3' => 0,
                    'jardim_3a4' => 0,
                    'preI_4a5' => 0,
                    'preII_5a6' => 0,
                    'ano1_6a7' => 0,
                    'ano2_7a8' => 0,
                    'ano3_8a9' => 0,
                    'ano4_9a10' => 0,
                ],
                'atualizado_em' => '2025-01-05T12:00:00Z',
                'escolas' => [$rawSchool],
            ],
        ];

        $this->repository
            ->expects(self::once())
            ->method('listNeighborhoodAggregates')
            ->with(1, ['status' => 'pendente'], true)
            ->willReturn($repositoryResponse);

        $result = $this->service->listNeighborhoodAggregates(1, ['status' => 'pendente'], true);

        self::assertCount(1, $result);
        self::assertSame('Centro', $result[0]['nome']);
        self::assertArrayHasKey('escolas', $result[0]);
        self::assertSame('Escola Modelo', $result[0]['escolas'][0]['nome']);
        self::assertSame(['Matutino', 'Vespertino'], $result[0]['escolas'][0]['periodos']);
    }

    public function testListCityAggregatesConvertsNestedNeighborhoodSchools(): void
    {
        $rawSchool = $this->sampleSchoolRow();
        $repositoryResponse = [
            [
                'id' => 1,
                'nome' => 'Tijucas',
                'sigla_uf' => 'SC',
                'totais' => [
                    'total_escolas' => 1,
                    'total_bairros' => 1,
                    'total_alunos' => 300,
                    'panfletagem_feita' => 1,
                    'panfletagem_pendente' => 0,
                ],
                'periodos' => [
                    'Matutino' => 1,
                    'Vespertino' => 1,
                    'Noturno' => 0,
                ],
                'etapas' => [
                    'bercario_0a1' => 0,
                    'bercario_1a2' => 0,
                    'maternal_2a3' => 0,
                    'jardim_3a4' => 0,
                    'preI_4a5' => 0,
                    'preII_5a6' => 0,
                    'ano1_6a7' => 0,
                    'ano2_7a8' => 0,
                    'ano3_8a9' => 0,
                    'ano4_9a10' => 0,
                ],
                'atualizado_em' => '2025-01-05T12:00:00Z',
                'bairros' => [
                    [
                        'id' => 20,
                        'nome' => 'Centro',
                        'cidade_id' => 1,
                        'totais' => [
                            'total_escolas' => 1,
                            'total_alunos' => 300,
                            'panfletagem_feita' => 1,
                            'panfletagem_pendente' => 0,
                        ],
                        'periodos' => [
                            'Matutino' => 1,
                            'Vespertino' => 1,
                            'Noturno' => 0,
                        ],
                        'etapas' => [
                            'bercario_0a1' => 0,
                            'bercario_1a2' => 0,
                            'maternal_2a3' => 0,
                            'jardim_3a4' => 0,
                            'preI_4a5' => 0,
                            'preII_5a6' => 0,
                            'ano1_6a7' => 0,
                            'ano2_7a8' => 0,
                            'ano3_8a9' => 0,
                            'ano4_9a10' => 0,
                        ],
                        'atualizado_em' => '2025-01-05T12:00:00Z',
                        'escolas' => [$rawSchool],
                    ],
                ],
            ],
        ];

        $this->repository
            ->expects(self::once())
            ->method('listCityAggregates')
            ->with(['status' => 'pendente'], true)
            ->willReturn($repositoryResponse);

        $result = $this->service->listCityAggregates(['status' => 'pendente'], true);

        self::assertCount(1, $result);
        self::assertArrayHasKey('bairros', $result[0]);
        self::assertSame('Escola Modelo', $result[0]['bairros'][0]['escolas'][0]['nome']);
    }

    public function testListNeighborhoodAggregatesHandlesEmptyFixtures(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('listNeighborhoodAggregates')
            ->with(2, ['status' => 'todos'], true)
            ->willReturn(AggregatesFixtures::neighborhoodWithoutSchools());

        $result = $this->service->listNeighborhoodAggregates(2, ['status' => 'todos'], true);

        self::assertCount(1, $result);
        self::assertSame([], $result[0]['escolas']);
        self::assertSame(0, $result[0]['totais']['total_escolas']);
    }

    public function testMakeAggregatesEtagReflectsFilters(): void
    {
        $fixture = AggregatesFixtures::cityWithoutNeighborhoods();

        $etagDefault = $this->service->makeAggregatesEtag($fixture, ['status' => 'todos'], 'scope', true);
        $etagFiltered = $this->service->makeAggregatesEtag($fixture, ['status' => 'pendente'], 'scope', true);

        self::assertNotNull($etagDefault);
        self::assertNotNull($etagFiltered);
        self::assertNotSame($etagDefault, $etagFiltered);
    }

    public function testMakeAggregatesEtagIncludesNestedDataWhenRequested(): void
    {
        $rawSchool = $this->sampleSchoolRow();
        $rawSchool['versao_row'] = 9;
        $rawSchool['atualizado_em'] = '2025-01-06T08:00:00Z';
        $city = [
            [
                'id' => 1,
                'nome' => 'Tijucas',
                'sigla_uf' => 'SC',
                'totais' => [
                    'total_escolas' => 1,
                    'total_bairros' => 1,
                    'total_alunos' => 300,
                    'panfletagem_feita' => 1,
                    'panfletagem_pendente' => 0,
                ],
                'periodos' => [
                    'Matutino' => 1,
                    'Vespertino' => 1,
                    'Noturno' => 0,
                ],
                'etapas' => [
                    'bercario_0a1' => 0,
                    'bercario_1a2' => 0,
                    'maternal_2a3' => 0,
                    'jardim_3a4' => 0,
                    'preI_4a5' => 0,
                    'preII_5a6' => 0,
                    'ano1_6a7' => 0,
                    'ano2_7a8' => 0,
                    'ano3_8a9' => 0,
                    'ano4_9a10' => 0,
                ],
                'atualizado_em' => '2025-01-05T12:00:00Z',
                'versao_row' => 3,
                'bairros' => [
                    [
                        'id' => 20,
                        'nome' => 'Centro',
                        'cidade_id' => 1,
                        'totais' => [
                            'total_escolas' => 1,
                            'total_alunos' => 300,
                            'panfletagem_feita' => 1,
                            'panfletagem_pendente' => 0,
                        ],
                        'periodos' => [
                            'Matutino' => 1,
                            'Vespertino' => 1,
                            'Noturno' => 0,
                        ],
                        'etapas' => [
                            'bercario_0a1' => 0,
                            'bercario_1a2' => 0,
                            'maternal_2a3' => 0,
                            'jardim_3a4' => 0,
                            'preI_4a5' => 0,
                            'preII_5a6' => 0,
                            'ano1_6a7' => 0,
                            'ano2_7a8' => 0,
                            'ano3_8a9' => 0,
                            'ano4_9a10' => 0,
                        ],
                        'atualizado_em' => '2025-01-06T07:00:00Z',
                        'versao_row' => 5,
                        'escolas' => [$rawSchool],
                    ],
                ],
            ],
        ];

        $etagWithoutNested = $this->service->makeAggregatesEtag($city, [], 'scope', false);
        $etagWithNested = $this->service->makeAggregatesEtag($city, [], 'scope', true);

        self::assertNotNull($etagWithoutNested);
        self::assertNotNull($etagWithNested);
        self::assertNotSame($etagWithoutNested, $etagWithNested);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleSchoolRow(): array
    {
        return [
            'id' => 100,
            'cidade_id' => 1,
            'cidade_nome' => 'Tijucas',
            'cidade_sigla_uf' => 'SC',
            'bairro_id' => 20,
            'bairro_nome' => 'Centro',
            'tipo' => 'CEI',
            'nome' => 'Escola Modelo',
            'diretor' => 'Ana Martins',
            'endereco' => 'Rua das Flores, 100',
            'total_alunos' => 300,
            'panfletagem' => true,
            'panfletagem_atualizado_em' => '2025-01-05T12:00:00Z',
            'panfletagem_usuario' => [
                'id' => 9,
                'nome' => 'Promotor X',
            ],
            'indicadores' => [
                'tem_pre' => true,
            ],
            'obs' => 'Visita concluida',
            'versao_row' => 3,
            'criado_em' => '2025-01-04T09:00:00Z',
            'atualizado_em' => '2025-01-05T12:00:00Z',
            'periodos' => ['Matutino', 'Vespertino'],
        ];
    }
}
