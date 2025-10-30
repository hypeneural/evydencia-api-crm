<?php

declare(strict_types=1);

namespace Tests\Fixtures;

final class AggregatesFixtures
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function cityWithoutNeighborhoods(): array
    {
        return [
            [
                'id' => 10,
                'nome' => 'Cidade Sem Bairros',
                'sigla_uf' => 'SC',
                'totais' => [
                    'total_escolas' => 0,
                    'total_bairros' => 0,
                    'total_alunos' => 0,
                    'panfletagem_feita' => 0,
                    'panfletagem_pendente' => 0,
                ],
                'periodos' => [
                    'Matutino' => 0,
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
                'atualizado_em' => null,
                'versao_row' => 0,
                'bairros' => [],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function neighborhoodWithoutSchools(): array
    {
        return [
            [
                'id' => 33,
                'nome' => 'Bairro Sem Escolas',
                'cidade_id' => 1,
                'totais' => [
                    'total_escolas' => 0,
                    'total_alunos' => 0,
                    'panfletagem_feita' => 0,
                    'panfletagem_pendente' => 0,
                ],
                'periodos' => [
                    'Matutino' => 0,
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
                'atualizado_em' => null,
                'versao_row' => 0,
                'escolas' => [],
            ],
        ];
    }
}

