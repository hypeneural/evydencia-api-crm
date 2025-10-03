<?php

declare(strict_types=1);

namespace App\Application\Support;

use App\Domain\Exception\ValidationException;
use App\Settings\Settings;
use DateTimeImmutable;
use DateTimeZone;
use Exception;

final class CampaignSchedulePayloadNormalizer
{
    private DateTimeZone $appTimezone;
    private DateTimeZone $utc;

    public function __construct(private readonly Settings $settings)
    {
        $app = $settings->getApp();
        $timezone = $app['timezone'] ?? 'UTC';
        if (!is_string($timezone) || trim($timezone) === '') {
            $timezone = 'UTC';
        }

        $this->appTimezone = new DateTimeZone($timezone);
        $this->utc = new DateTimeZone('UTC');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function normalize(array $payload): array
    {
        $normalized = $payload;
        $errors = [];

        $campaign = $this->normalizeInteger($payload['campaign'] ?? null);
        if ($campaign === null || $campaign <= 0) {
            $errors[] = ['field' => 'campaign', 'message' => 'informe um numero inteiro valido.'];
        } else {
            $normalized['campaign'] = $campaign;
        }

        $startAt = $this->normalizeDateTimeField($payload['start_at'] ?? null, 'start_at', true, $errors);
        $finishAt = $this->normalizeDateTimeField($payload['finish_at'] ?? null, 'finish_at', false, $errors);

        if ($startAt !== null) {
            $normalized['start_at'] = $this->formatDateTime($startAt);
        }

        if ($finishAt !== null) {
            $normalized['finish_at'] = $this->formatDateTime($finishAt);
        } elseif (array_key_exists('finish_at', $payload)) {
            $normalized['finish_at'] = null;
        }

        if ($startAt !== null && $finishAt !== null && $finishAt < $startAt) {
            $errors[] = ['field' => 'finish_at', 'message' => 'deve ser posterior a start_at.'];
        }

        $contacts = $this->normalizeContacts($payload['contacts'] ?? null, $errors);
        if ($contacts !== null) {
            $normalized['contacts'] = $contacts;
        }

        $normalized['use_leads_system'] = $this->normalizeBoolean($payload['use_leads_system'] ?? false);

        if (array_key_exists('instance', $payload)) {
            $instance = $this->normalizeInteger($payload['instance']);
            if ($instance === null || $instance < 0) {
                $errors[] = ['field' => 'instance', 'message' => 'deve ser um numero inteiro valido.'];
                unset($normalized['instance']);
            } else {
                $normalized['instance'] = $instance;
            }
        }

        if (array_key_exists('order', $payload)) {
            $orderValue = $payload['order'];
            if ($orderValue === null || $orderValue === '') {
                $normalized['order'] = null;
            } else {
                $orderId = $this->normalizeInteger($orderValue);
                if ($orderId === null || $orderId <= 0) {
                    $errors[] = ['field' => 'order', 'message' => 'deve ser um numero inteiro valido.'];
                } else {
                    $normalized['order'] = $orderId;
                }
            }
        }

        if (array_key_exists('customer', $payload)) {
            $customerValue = $payload['customer'];
            if ($customerValue === null || $customerValue === '') {
                $normalized['customer'] = null;
            } else {
                $customerId = $this->normalizeInteger($customerValue);
                if ($customerId === null || $customerId <= 0) {
                    $errors[] = ['field' => 'customer', 'message' => 'deve ser um numero inteiro valido.'];
                } else {
                    $normalized['customer'] = $customerId;
                }
            }
        }

        $normalized['product'] = $this->normalizeProduct($payload['product'] ?? null);

        if (!isset($normalized['type']) || !is_string($normalized['type']) || trim((string) $normalized['type']) === '') {
            $normalized['type'] = 'campaign';
        } else {
            $normalized['type'] = trim((string) $normalized['type']);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, string>> $errors
     */
    private function normalizeDateTimeField(mixed $value, string $field, bool $required, array &$errors): ?DateTimeImmutable
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            if ($required) {
                $errors[] = ['field' => $field, 'message' => 'campo obrigatorio.'];
            }

            return null;
        }

        if (!is_string($value)) {
            $errors[] = ['field' => $field, 'message' => 'deve ser uma data valida (ISO 8601).'];

            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            if ($required) {
                $errors[] = ['field' => $field, 'message' => 'campo obrigatorio.'];
            }

            return null;
        }

        try {
            $date = new DateTimeImmutable($candidate, $this->appTimezone);
        } catch (Exception) {
            $errors[] = ['field' => $field, 'message' => 'deve ser uma data valida (ISO 8601).'];

            return null;
        }

        return $date->setTimezone($this->utc);
    }

    private function normalizeContacts(mixed $contacts, array &$errors): ?string
    {
        if ($contacts === null) {
            $errors[] = ['field' => 'contacts', 'message' => 'informe ao menos um contato.'];

            return null;
        }

        if (is_string($contacts)) {
            $normalized = $this->normalizeContactsString($contacts);
            if ($normalized === '') {
                $errors[] = ['field' => 'contacts', 'message' => 'informe ao menos um contato.'];

                return null;
            }

            return $normalized;
        }

        if (!is_array($contacts)) {
            $errors[] = ['field' => 'contacts', 'message' => 'formato invalido para contatos.'];

            return null;
        }

        if ($contacts === []) {
            $errors[] = ['field' => 'contacts', 'message' => 'informe ao menos um contato.'];

            return null;
        }

        if (!array_is_list($contacts)) {
            return $this->buildContactsFromAssociative($contacts, $errors);
        }

        $first = $contacts[0] ?? null;
        if (is_array($first)) {
            return $this->buildContactsFromRows($contacts, $errors);
        }

        $lines = [];
        foreach ($contacts as $entry) {
            if (!is_scalar($entry)) {
                $errors[] = ['field' => 'contacts', 'message' => 'formato invalido para contatos.'];

                return null;
            }

            $line = $this->sanitizeScalar($entry);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            $errors[] = ['field' => 'contacts', 'message' => 'informe ao menos um contato.'];

            return null;
        }

        return $this->implodeLines($lines);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildContactsFromRows(array $rows, array &$errors): ?string
    {
        $columns = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                $errors[] = ['field' => 'contacts', 'message' => 'formato invalido para contatos.'];

                return null;
            }

            foreach ($row as $column => $value) {
                if (!is_string($column) || $column === '') {
                    continue;
                }

                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        if ($columns === []) {
            $errors[] = ['field' => 'contacts', 'message' => 'defina ao menos uma coluna para contatos.'];

            return null;
        }

        $lines = [];
        $lines[] = implode(';', $columns);

        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $column) {
                $cell = $row[$column] ?? '';
                if (is_array($cell)) {
                    $cell = '';
                }
                $values[] = $this->sanitizeScalar($cell);
            }
            $lines[] = implode(';', $values);
        }

        return $this->implodeLines($lines);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildContactsFromAssociative(array $row, array &$errors): ?string
    {
        if ($row === []) {
            $errors[] = ['field' => 'contacts', 'message' => 'informe ao menos um contato.'];

            return null;
        }

        $columns = [];
        foreach ($row as $column => $_value) {
            if (!is_string($column) || $column === '') {
                continue;
            }
            $columns[] = $column;
        }

        if ($columns === []) {
            $errors[] = ['field' => 'contacts', 'message' => 'defina ao menos uma coluna para contatos.'];

            return null;
        }

        $lines = [];
        $lines[] = implode(';', $columns);

        $values = [];
        foreach ($columns as $column) {
            $values[] = $this->sanitizeScalar($row[$column] ?? '');
        }
        $lines[] = implode(';', $values);

        return $this->implodeLines($lines);
    }

    private function normalizeContactsString(string $contacts): string
    {
        $normalized = str_replace(["
", ""], "
", $contacts);
        $lines = array_map(static fn (string $line): string => trim($line), explode("
", $normalized));
        $lines = array_filter($lines, static fn (string $line): bool => $line !== '');

        return implode("
", $lines);
    }

    private function implodeLines(array $lines): string
    {
        $sanitized = [];
        foreach ($lines as $line) {
            $sanitized[] = trim(str_replace(["
", ""], "
", (string) $line));
        }

        $sanitized = array_filter($sanitized, static fn (string $line): bool => $line !== '');

        return implode("
", $sanitized);
    }

    private function normalizeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return null;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return false;
            }

            return in_array($normalized, ['1', 'true', 'yes', 'sim', 'on'], true);
        }

        return false;
    }

    private function sanitizeScalar(mixed $value): string
    {
        if (is_string($value)) {
            return trim(str_replace(["
", ""], ' ', $value));
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeProduct(mixed $product): array
    {
        $result = [
            'reference' => null,
            'slug' => null,
        ];

        if (!is_array($product)) {
            return $result;
        }

        $source = $product;
        if (array_key_exists('referecen', $source) && !array_key_exists('reference', $source)) {
            $source['reference'] = $source['referecen'];
        }
        unset($source['referecen']);

        $reference = $this->normalizeNullableString($source['reference'] ?? null);
        if ($reference !== null || array_key_exists('reference', $source)) {
            $result['reference'] = $reference;
        }

        $slug = $this->normalizeNullableString($source['slug'] ?? null);
        if ($slug !== null || array_key_exists('slug', $source)) {
            $result['slug'] = $slug;
        }

        foreach (['uuid', 'name'] as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }

            $value = $source[$key];
            if ($value === null) {
                $result[$key] = null;
                continue;
            }

            $string = $this->sanitizeScalar($value);
            if ($string !== '') {
                $result[$key] = $string;
            }
        }

        return $result;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = $this->sanitizeScalar($value);

        return $string === '' ? null : $string;
    }

    private function formatDateTime(DateTimeImmutable $date): string
    {
        return $date->setTimezone($this->utc)->format('Y-m-d\TH:i:s\Z');
    }
}
