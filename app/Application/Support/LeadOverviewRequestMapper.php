<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Application\DTO\LeadOverviewOptions;
use App\Domain\Exception\ValidationException;
use App\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class LeadOverviewRequestMapper
{
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    private DateTimeZone $timezone;

    public function __construct(Settings $settings)
    {
        $app = $settings->getApp();
        $timezone = is_string($app['timezone'] ?? null) ? $app['timezone'] : 'UTC';

        try {
            $this->timezone = new DateTimeZone($timezone);
        } catch (Exception) {
            $this->timezone = new DateTimeZone('UTC');
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    public function map(array $query): LeadOverviewOptions
    {
        $errors = [];

        $campaignIds = $this->parseCampaignIds($query['campaign_id'] ?? null);
        if ($campaignIds === []) {
            $errors[] = [
                'field' => 'campaign_id',
                'message' => 'Informe um ou mais IDs de campanha validos.',
            ];
        }

        $limit = $this->parseLimit($query['limit'] ?? null, $errors);

        $dateFrom = $this->parseDate($query['date_from'] ?? null, false, $errors, 'date_from');
        $dateTo = $this->parseDate($query['date_to'] ?? null, true, $errors, 'date_to');

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            $errors[] = [
                'field' => 'date_to',
                'message' => 'date_to deve ser maior ou igual a date_from.',
            ];
        }

        $dedupeBy = $this->parseDedupeBy($query['dedupe_by'] ?? null, $errors);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new LeadOverviewOptions(
            $campaignIds,
            $limit,
            $dateFrom,
            $dateTo,
            $dedupeBy
        );
    }

    /**
     * @return array<int, int>
     */
    private function parseCampaignIds(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $candidates = [];
        if (is_array($value)) {
            $candidates = $value;
        } else {
            $stringValue = is_string($value) ? $value : (string) $value;
            $parts = array_filter(array_map('trim', explode(',', $stringValue)), static fn (string $part): bool => $part !== '');
            $candidates = $parts;
        }

        $ids = [];
        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            if (is_array($candidate)) {
                $candidate = current($candidate);
            }

            $string = is_string($candidate) ? trim($candidate) : (string) $candidate;
            if ($string === '') {
                continue;
            }

            if (!preg_match('/^[0-9]+$/', $string)) {
                return [];
            }

            $id = (int) $string;
            if ($id <= 0) {
                return [];
            }

            $ids[] = $id;
        }

        if ($ids === []) {
            return [];
        }

        return array_values(array_unique($ids));
    }

    private function parseLimit(mixed $value, array &$errors): int
    {
        if ($value === null || $value === '') {
            return self::DEFAULT_LIMIT;
        }

        $candidate = null;
        if (is_numeric($value)) {
            $candidate = (int) $value;
        } elseif (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            $candidate = (int) $value;
        }

        if ($candidate === null || $candidate <= 0) {
            $errors[] = [
                'field' => 'limit',
                'message' => 'limit deve ser um numero inteiro positivo.',
            ];

            return self::DEFAULT_LIMIT;
        }

        if ($candidate > self::MAX_LIMIT) {
            $candidate = self::MAX_LIMIT;
        }

        return $candidate;
    }

    private function parseDedupeBy(mixed $value, array &$errors): string
    {
        if (!is_string($value) || $value === '') {
            return LeadOverviewOptions::DEDUPE_BY_LEAD_ID;
        }

        $normalized = strtolower(trim($value));
        $allowed = [
            LeadOverviewOptions::DEDUPE_BY_LEAD_ID,
            LeadOverviewOptions::DEDUPE_BY_WHATSAPP,
        ];

        if (!in_array($normalized, $allowed, true)) {
            $errors[] = [
                'field' => 'dedupe_by',
                'message' => sprintf('dedupe_by deve ser %s.', implode(' ou ', $allowed)),
            ];

            return LeadOverviewOptions::DEDUPE_BY_LEAD_ID;
        }

        return $normalized;
    }

    private function parseDate(mixed $value, bool $endOfDay, array &$errors, string $field): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $string = is_string($value) ? trim($value) : (string) $value;
        if ($string === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $string) === 1) {
                $date = new DateTimeImmutable($string, $this->timezone);

                return $endOfDay
                    ? $date->setTime(23, 59, 59)
                    : $date->setTime(0, 0, 0);
            }

            return new DateTimeImmutable($string, $this->timezone);
        } catch (Exception) {
            $errors[] = [
                'field' => $field,
                'message' => sprintf('%s deve ser uma data ISO-8601 valida.', $field),
            ];

            return null;
        }
    }
}
