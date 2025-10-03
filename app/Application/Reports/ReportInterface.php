<?php

declare(strict_types=1);

namespace App\Application\Reports;

interface ReportInterface
{
    public function key(): string;

    public function title(): string;

    public function description(): string;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function params(): array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function columns(): array;

    /**
     * @param array<string, mixed> $input
     */
    public function run(array $input): ReportResult;

    public function cacheTtl(): int;
}
