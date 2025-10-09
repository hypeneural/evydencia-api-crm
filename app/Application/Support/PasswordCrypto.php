<?php

declare(strict_types=1);

namespace App\Application\Support;

use RuntimeException;

final class PasswordCrypto
{
    private const ALGORITHM = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    /**
     * @var string
     */
    private string $key;

    public function __construct(string $key)
    {
        $normalized = $this->normalizeKey($key);

        if ($normalized === null) {
            throw new RuntimeException('Password encryption key must be a 32-byte string or 64-char hex.');
        }

        $this->key = $normalized;
    }

    public function encrypt(string $plainText): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::ALGORITHM,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false || $tag === '') {
            throw new RuntimeException('Failed to encrypt password payload.');
        }

        return sprintf(
            '%s:%s:%s',
            bin2hex($iv),
            bin2hex($tag),
            bin2hex($cipherText)
        );
    }

    public function decrypt(string $payload): string
    {
        $parts = explode(':', trim($payload));
        if (count($parts) !== 3) {
            throw new RuntimeException('Encrypted password payload has an invalid format.');
        }

        [$ivHex, $tagHex, $cipherHex] = $parts;

        if ($ivHex === '' || $tagHex === '' || $cipherHex === '') {
            throw new RuntimeException('Encrypted password payload is incomplete.');
        }

        $iv = $this->hexToBinary($ivHex);
        $tag = $this->hexToBinary($tagHex);
        $cipher = $this->hexToBinary($cipherHex);

        if ($iv === null || $tag === null || $cipher === null) {
            throw new RuntimeException('Encrypted password payload contains invalid hex data.');
        }

        $plainText = openssl_decrypt(
            $cipher,
            self::ALGORITHM,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException('Failed to decrypt password payload.');
        }

        return $plainText;
    }

    private function normalizeKey(string $key): ?string
    {
        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_xdigit($trimmed) && strlen($trimmed) === 64) {
            $binary = hex2bin($trimmed);

            return $binary === false ? null : $binary;
        }

        if (strlen($trimmed) === 32) {
            return $trimmed;
        }

        return null;
    }

    private function hexToBinary(string $value): ?string
    {
        if ($value === '' || !ctype_xdigit($value) || strlen($value) % 2 !== 0) {
            return null;
        }

        $binary = hex2bin($value);

        return $binary === false ? null : $binary;
    }
}

