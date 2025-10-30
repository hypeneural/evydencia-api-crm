<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class SchoolPanfletagemLog
{
    public function __construct(
        public readonly int $id,
        public readonly bool $statusAnterior,
        public readonly bool $statusNovo,
        public readonly ?string $observacao,
        public readonly ?array $usuario,
        public readonly ?string $criadoEm
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRepositoryRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (bool) $row['status_anterior'],
            (bool) $row['status_novo'],
            isset($row['observacao']) ? (string) $row['observacao'] : null,
            isset($row['usuario']) && is_array($row['usuario']) ? $row['usuario'] : null,
            isset($row['criado_em']) ? (string) $row['criado_em'] : null
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status_anterior' => $this->statusAnterior,
            'status_novo' => $this->statusNovo,
            'observacao' => $this->observacao,
            'usuario' => $this->usuario,
            'criado_em' => $this->criadoEm,
        ];
    }
}
