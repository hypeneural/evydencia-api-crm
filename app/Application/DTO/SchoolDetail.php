<?php

declare(strict_types=1);

namespace App\Application\DTO;

/**
 * @extends SchoolSummary
 */
final class SchoolDetail extends SchoolSummary
{
    /**
     * @param array<int, string> $periodos
     * @param array<string, mixed>|null $indicadores
     * @param array<string, int> $etapas
     */
    public function __construct(
        int $id,
        int $cidadeId,
        string $cidadeNome,
        string $cidadeSiglaUf,
        int $bairroId,
        string $bairroNome,
        string $tipo,
        string $nome,
        ?string $diretor,
        ?string $endereco,
        int $totalAlunos,
        bool $panfletagem,
        ?string $panfletagemAtualizadoEm,
        ?array $panfletagemUsuario,
        ?array $indicadores,
        ?string $obs,
        int $versaoRow,
        ?string $criadoEm,
        ?string $atualizadoEm,
        array $periodos,
        public readonly array $etapas
    ) {
        parent::__construct(
            $id,
            $cidadeId,
            $cidadeNome,
            $cidadeSiglaUf,
            $bairroId,
            $bairroNome,
            $tipo,
            $nome,
            $diretor,
            $endereco,
            $totalAlunos,
            $panfletagem,
            $panfletagemAtualizadoEm,
            $panfletagemUsuario,
            $indicadores,
            $obs,
            $versaoRow,
            $criadoEm,
            $atualizadoEm,
            $periodos
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRepositoryRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['cidade_id'],
            (string) $row['cidade_nome'],
            (string) $row['cidade_sigla_uf'],
            (int) $row['bairro_id'],
            (string) $row['bairro_nome'],
            (string) $row['tipo'],
            (string) $row['nome'],
            isset($row['diretor']) ? (string) $row['diretor'] : null,
            isset($row['endereco']) ? (string) $row['endereco'] : null,
            (int) $row['total_alunos'],
            (bool) $row['panfletagem'],
            isset($row['panfletagem_atualizado_em']) ? (string) $row['panfletagem_atualizado_em'] : null,
            isset($row['panfletagem_usuario']) && is_array($row['panfletagem_usuario']) ? $row['panfletagem_usuario'] : null,
            isset($row['indicadores']) && is_array($row['indicadores']) ? $row['indicadores'] : null,
            isset($row['obs']) ? (string) $row['obs'] : null,
            (int) $row['versao_row'],
            isset($row['criado_em']) ? (string) $row['criado_em'] : null,
            isset($row['atualizado_em']) ? (string) $row['atualizado_em'] : null,
            array_map(static fn ($value): string => (string) $value, $row['periodos'] ?? []),
            array_map(static fn ($value): int => (int) $value, $row['etapas'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            parent::toArray(),
            [
                'etapas' => $this->etapas,
            ]
        );
    }
}
