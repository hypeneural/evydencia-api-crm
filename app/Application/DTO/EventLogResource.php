<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class EventLogResource
{
    /**
     * @param array<string, mixed>|null $payloadAntigo
     * @param array<string, mixed>|null $payloadNovo
     */
    public function __construct(
        public readonly int $id,
        public readonly string $acao,
        public readonly ?int $usuarioId,
        public readonly ?array $payloadAntigo,
        public readonly ?array $payloadNovo,
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
            (string) $row['acao'],
            isset($row['usuario_id']) ? (int) $row['usuario_id'] : null,
            isset($row['payload_antigo']) && is_array($row['payload_antigo']) ? $row['payload_antigo'] : null,
            isset($row['payload_novo']) && is_array($row['payload_novo']) ? $row['payload_novo'] : null,
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
            'acao' => $this->acao,
            'usuario_id' => $this->usuarioId,
            'payload_antigo' => $this->payloadAntigo,
            'payload_novo' => $this->payloadNovo,
            'criado_em' => $this->criadoEm,
        ];
    }
}
