<?php

declare(strict_types=1);

namespace App\Application\Reports;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

final class ReportHelpers
{
    private const BONUS_BY_REF = [
        'hohoho' => 3,
        'enatal' => 6,
        'boasfestas' => 10,
    ];

    /**
     * @param array<string, mixed> $order
     */
    public function isCanceled(array $order): bool
    {
        $status = (string) ($order['status'] ?? '');

        return in_array($status, ['canceled', 'cancelled', 'refunded', 'refused'], true);
    }

    public function calculateAge(?string $birthDate): ?int
    {
        if ($birthDate === null || trim($birthDate) === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($birthDate);
        } catch (\Exception) {
            return null;
        }

        $now = new DateTimeImmutable('today');

        return (int) $date->diff($now)->format('%y');
    }

    public function suffix8(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if ($normalized === '') {
            return null;
        }

        return substr($normalized, -8);
    }

    public function phoneKey(?string $phone): ?string
    {
        $suffix = $this->suffix8($phone);
        if ($suffix === null) {
            return null;
        }

        return $suffix;
    }

    public function approxEq(float $left, float $right, float $tolerance = 0.01): bool
    {
        return abs($left - $right) <= $tolerance;
    }

    /**
     * @param array<string, mixed> $product
     */
    public function isBonusPhotoProduct(array $product): bool
    {
        $name = strtolower((string) ($product['name'] ?? ''));
        $ref = strtolower((string) ($product['reference'] ?? ($product['ref'] ?? '')));
        $uuid = (string) ($product['uuid'] ?? ($product['id'] ?? ''));
        $unit = (float) ($product['unit_price'] ?? ($product['unit'] ?? 0));

        if ($uuid === 'db1f9211-c3b9-424e-950d-25d8ede0c440') {
            return true;
        }

        if ($unit === 20.0 && str_contains($name, 'foto revelada 15x21cm')) {
            return true;
        }

        if (str_contains($ref, '15x21')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, int> $maps
     * @return array{expected: int, reference: string|null}
     */
    public function detectExpectedPackage(array $order, array $maps = self::BONUS_BY_REF): array
    {
        $items = $order['items'] ?? [];
        if (!is_array($items)) {
            return ['expected' => 0, 'reference' => null];
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = $item['product'] ?? [];
            $ref = strtolower((string) ($product['reference'] ?? ($product['ref'] ?? '')));
            if ($ref === '') {
                $name = strtolower((string) ($product['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                foreach ($maps as $key => $qty) {
                    if (str_contains($name, strtolower($key))) {
                        return ['expected' => $qty, 'reference' => $key];
                    }
                }

                continue;
            }

            foreach ($maps as $key => $qty) {
                if (str_contains($ref, strtolower($key))) {
                    return ['expected' => $qty, 'reference' => $key];
                }
            }
        }

        return ['expected' => 0, 'reference' => null];
    }

    /**
     * @param array<string, mixed> $order
     * @return array<int, array<string, mixed>>
     */
    public function collectPhotoLines(array $order): array
    {
        $items = $order['items'] ?? [];
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = isset($item['product']) && is_array($item['product']) ? $item['product'] : [];
            if (!$this->isBonusPhotoProduct($product)) {
                continue;
            }

            $result[] = [
                'quantity' => (int) ($item['quantity'] ?? 0),
                'unit_price' => (float) ($item['unit_price'] ?? ($item['price'] ?? 0)),
                'total' => (float) ($item['total'] ?? 0),
                'discount' => (float) ($item['discount'] ?? 0),
                'entry' => (float) ($item['entry_value'] ?? 0),
                'meta' => $item,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $order
     */
    public function firstInstallmentConfirmed(array $order): ?array
    {
        $installments = $order['installments'] ?? [];
        if (!is_array($installments)) {
            return null;
        }

        $first = null;
        foreach ($installments as $installment) {
            if (!is_array($installment)) {
                continue;
            }

            if (!isset($installment['sequence']) || (int) $installment['sequence'] !== 1) {
                continue;
            }

            $first = $installment;
            break;
        }

        if ($first === null) {
            return null;
        }

        $transactions = $first['transactions'] ?? [];
        if (!is_array($transactions)) {
            return null;
        }

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $status = $transaction['status'] ?? [];
            if (is_array($status) && (int) ($status['id'] ?? 0) === 2) {
                return $transaction;
            }
        }

        return null;
    }

    public function sanitizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $phone) ?? '';
        if ($normalized === '') {
            return null;
        }

        if (strlen($normalized) >= 11) {
            return substr($normalized, -11);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $line
     */
    public function bonusQuantity(array $line): int
    {
        return (int) ($line['quantity'] ?? 0);
    }
}

final class RuleManager
{
    /**
     * @var array<int, array{ id: string, severity: string, callback: callable(array<string, mixed>): ?array }>
     */
    private array $rules = [];

    public function add(string $id, string $severity, callable $callback): self
    {
        $severity = strtolower($severity);
        if (!in_array($severity, ['info', 'warning', 'error'], true)) {
            throw new InvalidArgumentException('Invalid severity: ' . $severity);
        }

        $this->rules[] = [
            'id' => $id,
            'severity' => $severity,
            'callback' => $callback,
        ];

        return $this;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{issues: array<int, array<string, mixed>>, severity: string|null}
     */
    public function evaluate(array $record): array
    {
        $issues = [];
        $severities = ['info' => 0, 'warning' => 1, 'error' => 2];
        $max = null;

        foreach ($this->rules as $rule) {
            $result = ($rule['callback'])($record);
            if ($result === null || $result === false) {
                continue;
            }

            $issue = is_array($result) ? $result : [];
            $issue['rule'] = $rule['id'];
            $issue['severity'] = $rule['severity'];
            $issues[] = $issue;

            if ($max === null || $severities[$rule['severity']] > $severities[$max]) {
                $max = $rule['severity'];
            }
        }

        return [
            'issues' => $issues,
            'severity' => $max,
        ];
    }
}
