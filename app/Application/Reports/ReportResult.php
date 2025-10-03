<?php

declare(strict_types=1);

namespace App\Application\Reports;

final class ReportResult
{
    /**
     * @param array<int, mixed> $data
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $meta
     * @param array<int, array<string, mixed>> $columns
     */
    public function __construct(
        public array $data,
        public array $summary = [],
        public array $meta = [],
        public array $columns = []
    ) {
    }
}
