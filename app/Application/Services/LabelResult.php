<?php

declare(strict_types=1);

namespace App\Application\Services;

use Psr\Http\Message\StreamInterface;

/**
 * Value object that carries the generated label payload and metadata.
 */
final class LabelResult
{
    /**
     * @param array<string, mixed> $dataPayload
     */
    public function __construct(
        public readonly StreamInterface $stream,
        public readonly string $filename,
        public readonly string $absolutePath,
        public readonly int $width,
        public readonly int $height,
        public readonly int $dpi,
        public readonly int $bytes,
        public readonly array $dataPayload
    ) {
    }
}

