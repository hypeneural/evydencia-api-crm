<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Settings\Settings;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ScheduledPostMediaService
{
    private const IMAGE = 'image';
    private const VIDEO = 'video';

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $config;
    private string $storagePath;
    private string $baseUrl;

    public function __construct(
        Settings $settings,
        private readonly LoggerInterface $logger
    ) {
        $media = $settings->getMedia();
        $scheduled = $media['scheduled_posts'] ?? [];

        $storagePath = isset($scheduled['storage_path']) ? (string) $scheduled['storage_path'] : '';
        if ($storagePath === '') {
            throw new RuntimeException('Scheduled post storage path not configured.');
        }

        $this->storagePath = rtrim($storagePath, DIRECTORY_SEPARATOR . '/');
        $this->baseUrl = rtrim((string) ($scheduled['base_url'] ?? ''), "/ \t\n\r\0\x0B");

        if ($this->baseUrl === '') {
            throw new RuntimeException('Scheduled post media base URL not configured.');
        }

        $imageMaxBytes = isset($scheduled['image_max_bytes']) ? (int) $scheduled['image_max_bytes'] : (5 * 1024 * 1024);
        $videoMaxBytes = isset($scheduled['video_max_bytes']) ? (int) $scheduled['video_max_bytes'] : (10 * 1024 * 1024);

        $imageMimeTypes = $scheduled['image_mime_types'] ?? [];
        $videoMimeTypes = $scheduled['video_mime_types'] ?? [];

        if (!is_array($imageMimeTypes) || $imageMimeTypes === []) {
            $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        }

        if (!is_array($videoMimeTypes) || $videoMimeTypes === []) {
            $videoMimeTypes = ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo'];
        }

        $this->config = [
            self::IMAGE => [
                'max_bytes' => $imageMaxBytes > 0 ? $imageMaxBytes : (5 * 1024 * 1024),
                'mime_types' => $imageMimeTypes,
            ],
            self::VIDEO => [
                'max_bytes' => $videoMaxBytes > 0 ? $videoMaxBytes : (10 * 1024 * 1024),
                'mime_types' => $videoMimeTypes,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedTypes(): array
    {
        return array_keys($this->config);
    }

    /**
     * @return array<string, mixed>
     */
    public function store(UploadedFileInterface $file, string $type): array
    {
        $normalizedType = strtolower(trim($type));
        if (!isset($this->config[$normalizedType])) {
            throw new RuntimeException(sprintf('Tipo de mídia inválido: %s', $type));
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Falha ao receber o arquivo enviado.');
        }

        $size = $file->getSize();
        if ($size === null || $size <= 0) {
            throw new RuntimeException('Arquivo vazio ou tamanho desconhecido.');
        }

        $maxBytes = (int) $this->config[$normalizedType]['max_bytes'];
        if ($size > $maxBytes) {
            throw new RuntimeException(sprintf(
                'Arquivo excede o tamanho máximo permitido (%s MB).',
                number_format($maxBytes / 1048576, 2)
            ));
        }

        $clientFilename = $file->getClientFilename() ?? '';
        $mimeType = $file->getClientMediaType() ?? '';
        $allowedMimeTypes = $this->config[$normalizedType]['mime_types'];

        if ($mimeType === '' || !in_array($mimeType, $allowedMimeTypes, true)) {
            throw new RuntimeException(sprintf('Tipo de arquivo não permitido: %s', $mimeType));
        }

        $extension = $this->resolveExtension($clientFilename, $mimeType);

        $targetDirectory = $this->buildDirectory($normalizedType);
        $this->ensureDirectoryExists($targetDirectory);

        $filename = $this->generateFilename($extension);
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . $filename;

        try {
            $file->moveTo($targetPath);
        } catch (\Throwable $exception) {
            $this->logger->error('Failed to move uploaded scheduled post media', [
                'type' => $normalizedType,
                'target_path' => $targetPath,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Não foi possível salvar o arquivo enviado.', 0, $exception);
        }

        $relativePath = $normalizedType . '/' . $filename;
        $url = rtrim($this->baseUrl, '/') . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);

        return [
            'type' => $normalizedType,
            'url' => $url,
            'relative_path' => $relativePath,
            'mime_type' => $mimeType,
            'size' => $size,
        ];
    }

    private function buildDirectory(string $type): string
    {
        return $this->storagePath . DIRECTORY_SEPARATOR . $type;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de armazenamento para mídias.');
        }
    }

    private function generateFilename(string $extension): string
    {
        $random = bin2hex(random_bytes(16));

        return $random . '.' . $extension;
    }

    private function resolveExtension(string $clientFilename, string $mimeType): string
    {
        $extension = '';
        if ($clientFilename !== '') {
            $extension = strtolower((string) pathinfo($clientFilename, PATHINFO_EXTENSION));
        }

        if ($extension !== '') {
            return $this->sanitizeExtension($extension);
        }

        return $this->mapMimeToExtension($mimeType);
    }

    private function sanitizeExtension(string $extension): string
    {
        $sanitized = preg_replace('/[^a-z0-9]+/i', '', $extension) ?? '';

        return $sanitized !== '' ? strtolower($sanitized) : 'bin';
    }

    private function mapMimeToExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/mpeg' => 'mpeg',
            'video/quicktime' => 'mov',
            'video/x-msvideo' => 'avi',
            default => 'bin',
        };
    }
}
