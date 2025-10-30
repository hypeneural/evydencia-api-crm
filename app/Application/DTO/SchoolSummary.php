<?php

declare(strict_types=1);

namespace App\Application\DTO;

class SchoolSummary
{
    /**
     * @param array<int, string> $periodos
     * @param array<string, mixed>|null $indicadores
     */
    public function __construct(
        public readonly int $id,
        public readonly int $cidadeId,
        public readonly string $cidadeNome,
        public readonly string $cidadeSiglaUf,
        public readonly int $bairroId,
        public readonly string $bairroNome,
        public readonly string $tipo,
        public readonly string $nome,
        public readonly ?string $diretor,
        public readonly ?string $endereco,
        public readonly int $totalAlunos,
        public readonly bool $panfletagem,
        public readonly ?string $panfletagemAtualizadoEm,
        public readonly ?array $panfletagemUsuario,
        public readonly ?array $indicadores,
        public readonly ?string $obs,
        public readonly int $versaoRow,
        public readonly ?string $criadoEm,
        public readonly ?string $atualizadoEm,
        public readonly array $periodos
    ) {
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
            array_map(static fn ($value): string => (string) $value, $row['periodos'] ?? [])
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'cidade_id' => $this->cidadeId,
            'cidade_nome' => $this->cidadeNome,
            'cidade_sigla_uf' => $this->cidadeSiglaUf,
            'bairro_id' => $this->bairroId,
            'bairro_nome' => $this->bairroNome,
            'tipo' => $this->tipo,
            'nome' => $this->nome,
            'diretor' => $this->diretor,
            'endereco' => $this->endereco,
            'total_alunos' => $this->totalAlunos,
            'panfletagem' => $this->panfletagem,
            'panfletagem_atualizado_em' => $this->panfletagemAtualizadoEm,
            'panfletagem_usuario' => $this->panfletagemUsuario,
            'indicadores' => $this->indicadores,
            'obs' => $this->obs,
            'versao_row' => $this->versaoRow,
            'criado_em' => $this->criadoEm,
            'atualizado_em' => $this->atualizadoEm,
            'periodos' => $this->periodos,
        ];
    }
}
