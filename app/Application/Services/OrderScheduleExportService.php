<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use DateTimeImmutable;
use Exception;

final class OrderScheduleExportService
{
    private const DEFAULT_PAGE = 1;
    private const PAGE_SIZE = 200;

    public function __construct(
        private readonly OrderService $orderService
    ) {
    }

    /**
     * @return array{lines: array<int, string>}
     */
    public function exportContacts(DateTimeImmutable $start, DateTimeImmutable $end, string $traceId): array
    {
        $crmQuery = [
            'page' => self::DEFAULT_PAGE,
            'per_page' => self::PAGE_SIZE,
            // CRM range filters aceitam apenas datas (YYYY-MM-DD); aplicamos hora exata localmente.
            'order[session-start]' => $start->format('Y-m-d'),
            'order[session-end]' => $end->format('Y-m-d'),
        ];

        $options = new QueryOptions(
            $crmQuery,
            self::DEFAULT_PAGE,
            self::PAGE_SIZE,
            true,
            [
                ['field' => 'schedule_1', 'direction' => 'asc'],
            ],
            []
        );

        $result = $this->orderService->searchOrders($options, $traceId);
        $orders = $result['data'] ?? [];

        return [
            'lines' => $this->buildLines($orders, $start, $end),
        ];
    }

    /**
     * @param array<int, mixed> $orders
     * @return array<int, string>
     */
    private function buildLines(array $orders, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $seen = [];
        $lines = [];

        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }

            $scheduleAt = $this->parseSchedule($order['schedule_1'] ?? null);
            if ($scheduleAt === null || $scheduleAt < $start || $scheduleAt > $end) {
                continue;
            }

            if ($this->isCanceled($order)) {
                continue;
            }

            $customer = $order['customer'] ?? null;
            if (!is_array($customer)) {
                continue;
            }

            $rawWhatsapp = (string) ($customer['whatsapp'] ?? '');
            $normalizedWhatsapp = $this->normalizeWhatsapp($rawWhatsapp);
            if ($normalizedWhatsapp === '') {
                continue;
            }

            if (isset($seen[$normalizedWhatsapp])) {
                continue;
            }

            $firstName = $this->extractFirstName((string) ($customer['name'] ?? ''));
            if ($firstName === '') {
                $firstName = 'Cliente';
            }

            $seen[$normalizedWhatsapp] = true;
            $lines[] = sprintf('%s;%s', $normalizedWhatsapp, $firstName);
        }

        return $lines;
    }

    private function parseSchedule(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }

    private function isCanceled(array $order): bool
    {
        $statusId = $this->resolveStatusId($order);

        return $statusId === 1;
    }

    private function resolveStatusId(array $order): ?int
    {
        $status = $order['status'] ?? null;

        if (is_array($status)) {
            $status = $status['id'] ?? ($status['status_id'] ?? null);
        }

        if ($status === null && isset($order['status_id'])) {
            $status = $order['status_id'];
        }

        if ($status === null) {
            return null;
        }

        if (is_numeric($status)) {
            return (int) $status;
        }

        if (is_string($status) && ctype_digit($status)) {
            return (int) $status;
        }

        return null;
    }

    private function normalizeWhatsapp(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);

        return $digits ?? '';
    }

    private function extractFirstName(string $name): string
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed;
        $parts = explode(' ', $normalized);

        return $parts[0] ?? $normalized;
    }
}
