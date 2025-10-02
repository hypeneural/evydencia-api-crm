<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class QueryOptions
{
    /**
     * @param array<string, mixed> $crmQuery
     * @param array<int, array{field: string, direction: string}> $sort
     * @param array<string, array<int, string>> $fields
     */
    public function __construct(
        public readonly array $crmQuery,
        public readonly int $page,
        public readonly int $size,
        public readonly bool $all,
        public readonly array $sort,
        public readonly array $fields
    ) {
    }
}
