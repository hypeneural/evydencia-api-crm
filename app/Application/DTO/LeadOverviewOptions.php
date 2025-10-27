<?php

declare(strict_types=1);

namespace App\Application\DTO;

use DateTimeImmutable;

final class LeadOverviewOptions
{
    public const DEDUPE_BY_LEAD_ID = 'lead_id';
    public const DEDUPE_BY_WHATSAPP = 'whatsapp';

    /**
     * @param array<int, int> $campaignIds
     */
    public function __construct(
        public readonly array $campaignIds,
        public readonly int $limit,
        public readonly ?DateTimeImmutable $dateFrom,
        public readonly ?DateTimeImmutable $dateTo,
        public readonly string $dedupeBy
    ) {
    }
}

