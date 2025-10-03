<?php

declare(strict_types=1);

namespace App\Application\Reports;

interface ReportInterface
{
    public function key(): string;

    public function title(): string;

    /**
     * @return array<string, \Respect\Validation\Validatable>
     */
    public function rules(): array;

    /**
     * @return array<string, mixed>
     */
    public function defaultFilters(): array;

    /**
     * @return array<int, string>
     */
    public function columns(): array;

    /**
     * @return array<int, string>
     */
    public function sortable(): array;

    /**
     * @param array<string, mixed> $filters
     */
    public function run(array $filters): ReportResult;

    public function cacheTtl(): int;
}
