<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class SchoolObservation
{
    public function __construct(
        public readonly int $id,
        public readonly string $observacao,
        public readonly ?array $usuario,
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
            (string) $row['observacao'],
            isset($row['usuario']) && is_array($row['usuario']) ? $row['usuario'] : null,
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
            'observacao' => $this->observacao,
            'usuario' => $this->usuario,
            'criado_em' => $this->criadoEm,
            'atualizado_em' => $this->atualizadoEm,
        ];
    }
}
