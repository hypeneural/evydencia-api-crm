<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class EventResource
{
    public function __construct(
        public readonly int $id,
        public readonly string $titulo,
        public readonly ?string $descricao,
        public readonly ?string $cidade,
        public readonly ?string $local,
        public readonly ?string $inicio,
        public readonly ?string $fim,
        public readonly ?int $criadoPor,
        public readonly ?int $atualizadoPor,
        public readonly ?string $criadoEm,
        public readonly ?string $atualizadoEm
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRepositoryRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['titulo'],
            isset($row['descricao']) ? (string) $row['descricao'] : null,
            isset($row['cidade']) ? (string) $row['cidade'] : null,
            isset($row['local']) ? (string) $row['local'] : null,
            isset($row['inicio']) ? (string) $row['inicio'] : null,
            isset($row['fim']) ? (string) $row['fim'] : null,
            isset($row['criado_por']) ? (int) $row['criado_por'] : null,
            isset($row['atualizado_por']) ? (int) $row['atualizado_por'] : null,
            isset($row['criado_em']) ? (string) $row['criado_em'] : null,
            isset($row['atualizado_em']) ? (string) $row['atualizado_em'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'cidade' => $this->cidade,
            'local' => $this->local,
            'inicio' => $this->inicio,
            'fim' => $this->fim,
            'criado_por' => $this->criadoPor,
            'atualizado_por' => $this->atualizadoPor,
            'criado_em' => $this->criadoEm,
            'atualizado_em' => $this->atualizadoEm,
        ];
    }
}
