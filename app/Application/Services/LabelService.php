<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Exception\CrmRequestException;
use App\Domain\Exception\CrmUnavailableException;
use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Http\EvydenciaApiClient;
use App\Settings\Settings;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelInterface;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelQuartile;
use Endroid\QrCode\RoundBlockSizeMode\RoundBlockSizeModeMargin;
use Endroid\QrCode\Writer\PngWriter;
use GdImage;
use GuzzleHttp\Psr7\Utils;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class LabelService
{
    /**
     * Default configuration for the label generator. Adjust values here or override via labels settings.
     *
     * @var array<string, mixed>
     */
    private const DEFAULT_CONFIG = [
        'dpi' => 300,
        'width_mm' => 100,
        'height_mm' => 40,
        'margins_mm' => [
            'top' => 0,
            'right' => 0,
            'bottom' => 0,
            'left' => 0,
        ],
        'assets_dir' => '/public/etiqueta',
        'font' => 'ArchivoBlack-Regular.ttf',
        'background' => 'background.jpg',
        'use_background' => true,
        'color_bg' => [0, 0, 0],
        'color_fg' => [255, 255, 255],
        'output_dir' => '/public/etiqueta/out',
        'filename_pattern' => 'etiqueta_{id}.png',
        'footer_url_enabled' => true,
        'qr' => [
            'size_px' => 100,
            'margin_modules' => 0,
            'ec_level' => 'H',
        ],
        'layout' => [
            'nome_full' => ['x' => '11%', 'y' => '20%', 'size_pt' => 52, 'min_size_pt' => 20, 'max_w' => '60%', 'align' => 'left'],
            'primeiro' => ['x' => '83%', 'y' => '42%', 'size_pt' => 27, 'max_w' => '14%', 'align' => 'center'],
            'pacote' => ['x' => '12%', 'y' => '44%', 'size_pt' => 34, 'max_w' => '72%', 'align' => 'left'],
            'data' => ['x' => '11%', 'y' => '66%', 'size_pt' => 37, 'max_w' => '30%', 'align' => 'left'],
            'whats_ddd' => ['x' => '40%', 'y' => '66%', 'size_pt' => 20, 'max_w' => '7%', 'align' => 'left'],
            'whats_num' => ['x' => '45%', 'y' => '66%', 'size_pt' => 30, 'max_w' => '33%', 'align' => 'left'],
            'linha_url' => ['x' => '10%', 'y' => '84%', 'size_pt' => 28, 'max_w' => '50%', 'align' => 'left'],
            'qrcode' => ['size_px' => 100, 'x' => '83%', 'y' => '71%', 'margin_modules' => 0, 'ec_level' => 'H'],
        ],
        'url_template' => 'http://nossas.fotosdenatal.com/{id}',
        'copy_template' => '{primeiro}, acesse suas fotos no QR CODE ao lado',
        'mock_data' => [
            'nome_completo' => 'Anderson Marques Vieira',
            'primeiro_nome' => 'ANDERSON',
            'pacote' => 'Experiencia Entao e Natal',
            'data' => '25/09/25',
            'whats' => '48996425287',
            'id' => '1515',
        ],
    ];

    /**
     * Words that must not be abbreviated (normalized form).
     *
     * @var array<int, string>
     */
    private const NAME_DO_NOT_ABBREVIATE = [
        'de', 'da', 'do', 'das', 'dos', 'e', 'del', "d'", 'd', 'di', 'dal', 'van', 'von', 'la', 'le',
    ];

    private const NAME_MIN_KEEP = 2;

    /**
     * Only abbreviate when the name has more than this number of tokens.
     */
    private const NAME_ABBREVIATE_THRESHOLD = 3;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    public function __construct(
        private readonly Settings $settings,
        private readonly LoggerInterface $logger,
        private readonly EvydenciaApiClient $apiClient
    ) {
        $this->config = $this->hydrateConfig();
    }

    public function generateLabel(string $orderId, string $traceId): LabelResult
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('Extensao GD nao esta habilitada.');
        }

        $config = $this->config;
        $dpi = (int) $config['dpi'];
        $width = $this->mmToPx((float) $config['width_mm'], $dpi);
        $height = $this->mmToPx((float) $config['height_mm'], $dpi);

        $image = imagecreatetruecolor($width, $height);
        if (!$image instanceof GdImage) {
            throw new RuntimeException('Nao foi possivel inicializar o canvas da etiqueta.');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        try {
            $this->paintCanvas($image, $config);
            $metrics = $this->resolveMetrics($config, $dpi, $width, $height);
            $fontPath = $this->resolveFontPath($config);

            $data = $this->resolveData($orderId, $traceId);
            $displayName = $this->formatDisplayName($data['nome_completo']);
            if ($displayName === '') {
                $displayName = $data['nome_completo'];
            }
            $url = str_replace('{id}', $data['id'], (string) $config['url_template']);
            $copy = str_replace('{primeiro}', $data['primeiro_nome'], (string) $config['copy_template']);
            $whats = $this->formatWhatsApp($data['whats']);

            $layout = $config['layout'];
            $textColor = $this->allocateColor($image, $config['color_fg']);

            $nomeFullLayout = $layout['nome_full'];
            $nomeMaxWidth = $metrics['w']($nomeFullLayout['max_w']);
            $nomeMaxSize = (int) $nomeFullLayout['size_pt'];
            $nomeMinSize = isset($nomeFullLayout['min_size_pt']) ? (int) $nomeFullLayout['min_size_pt'] : max(16, (int) round($nomeMaxSize * 0.6));
            $nomeFontSize = $this->fitFontSize(
                $displayName,
                $fontPath,
                $nomeMaxWidth,
                $nomeMaxSize,
                $nomeMinSize
            );

            $this->drawSingleLineText(
                $image,
                $displayName,
                $metrics['x']($nomeFullLayout['x']),
                $metrics['y']($nomeFullLayout['y']),
                $nomeFontSize,
                $textColor,
                $fontPath,
                $nomeFullLayout['align']
            );

            $this->drawText(
                $image,
                $this->toUpper($data['primeiro_nome']),
                $metrics['x']($layout['primeiro']['x']),
                $metrics['y']($layout['primeiro']['y']),
                (int) $layout['primeiro']['size_pt'],
                $textColor,
                $fontPath,
                $layout['primeiro']['align'],
                $metrics['w']($layout['primeiro']['max_w'])
            );

            $this->drawText(
                $image,
                $data['pacote'],
                $metrics['x']($layout['pacote']['x']),
                $metrics['y']($layout['pacote']['y']),
                (int) $layout['pacote']['size_pt'],
                $textColor,
                $fontPath,
                $layout['pacote']['align'],
                $metrics['w']($layout['pacote']['max_w'])
            );

            $this->drawText(
                $image,
                $data['data'],
                $metrics['x']($layout['data']['x']),
                $metrics['y']($layout['data']['y']),
                (int) $layout['data']['size_pt'],
                $textColor,
                $fontPath,
                $layout['data']['align'],
                $metrics['w']($layout['data']['max_w'])
            );

            $this->drawText(
                $image,
                $whats['ddd'],
                $metrics['x']($layout['whats_ddd']['x']),
                $metrics['y']($layout['whats_ddd']['y']),
                (int) $layout['whats_ddd']['size_pt'],
                $textColor,
                $fontPath,
                $layout['whats_ddd']['align'],
                $metrics['w']($layout['whats_ddd']['max_w'])
            );

            $this->drawText(
                $image,
                $whats['number'],
                $metrics['x']($layout['whats_num']['x']),
                $metrics['y']($layout['whats_num']['y']),
                (int) $layout['whats_num']['size_pt'],
                $textColor,
                $fontPath,
                $layout['whats_num']['align'],
                $metrics['w']($layout['whats_num']['max_w'])
            );

            $footerText = trim((string) $copy);
            if (($config['footer_url_enabled'] ?? true) === true) {
                $footerText = trim($footerText . '  ' . $url);
            }

            if ($footerText !== '') {
                $this->drawText(
                    $image,
                    $footerText,
                    $metrics['x']($layout['linha_url']['x']),
                    $metrics['y']($layout['linha_url']['y']),
                    (int) $layout['linha_url']['size_pt'],
                    $textColor,
                    $fontPath,
                    $layout['linha_url']['align'],
                    $metrics['w']($layout['linha_url']['max_w'])
                );
            }

            $this->drawQrCode($image, $layout['qrcode'], $metrics, $url);

            $payload = [
                'id' => $data['id'],
                'uuid' => $data['uuid'],
                'nome_completo' => $data['nome_completo'],
                'primeiro_nome' => $data['primeiro_nome'],
                'pacote' => $data['pacote'],
                'data' => $data['data'],
                'whats' => $data['whats'],
                'nome_display' => $displayName,
                'url' => $url,
            ];

            $filename = str_replace('{id}', $data['id'], (string) $config['filename_pattern']);
            $absolutePath = $this->resolveOutputPath($config, $filename);
            $this->ensureDirectory(dirname($absolutePath));

            ob_start();
            imagepng($image);
            $binary = (string) ob_get_clean();

            if ($binary === '') {
                throw new RuntimeException('Falha ao gerar imagem da etiqueta.');
            }

            if (false === file_put_contents($absolutePath, $binary)) {
                throw new RuntimeException('Nao foi possivel salvar a etiqueta gerada.');
            }

            $stream = Utils::streamFor($binary);
            $bytes = strlen($binary);

            return new LabelResult(
                $stream,
                $filename,
                $absolutePath,
                $width,
                $height,
                $dpi,
                $bytes,
                $payload
            );
        } catch (Throwable $exception) {
            imagedestroy($image);
            $this->logger->error('Falha ao montar etiqueta', [
                'trace_id' => $traceId,
                'order_id' => $orderId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Nao foi possivel gerar a etiqueta.', 0, $exception);
        }
    }

    private function paintCanvas(GdImage $image, array $config): void
    {
        $backgroundColor = $this->allocateColor($image, $config['color_bg']);
        imagefilledrectangle($image, 0, 0, imagesx($image), imagesy($image), $backgroundColor);

        if (($config['use_background'] ?? false) !== true) {
            return;
        }

        $backgroundPath = $this->resolveBackgroundPath($config);
        if ($backgroundPath === null) {
            return;
        }

        $contents = @file_get_contents($backgroundPath);
        if ($contents === false) {
            $this->logger->warning('Nao foi possivel ler o background da etiqueta.', [
                'path' => $backgroundPath,
            ]);

            return;
        }

        $background = @imagecreatefromstring($contents);
        if (!$background instanceof GdImage) {
            $this->logger->warning('Background da etiqueta invalido.', [
                'path' => $backgroundPath,
            ]);

            return;
        }

        imagecopyresampled(
            $image,
            $background,
            0,
            0,
            0,
            0,
            imagesx($image),
            imagesy($image),
            imagesx($background),
            imagesy($background)
        );

        imagedestroy($background);
    }

    /**
     * @return array{x: callable, y: callable, w: callable, h: callable}
     */
    private function resolveMetrics(array $config, int $dpi, int $width, int $height): array
    {
        $margins = $config['margins_mm'];
        $pad = [
            'left' => $this->mmToPx((float) $margins['left'], $dpi),
            'right' => $this->mmToPx((float) $margins['right'], $dpi),
            'top' => $this->mmToPx((float) $margins['top'], $dpi),
            'bottom' => $this->mmToPx((float) $margins['bottom'], $dpi),
        ];

        $usableWidth = $width - $pad['left'] - $pad['right'];
        $usableHeight = $height - $pad['top'] - $pad['bottom'];

        return [
            'x' => fn ($value) => $pad['left'] + $this->relativeToPx($usableWidth, $value),
            'y' => fn ($value) => $pad['top'] + $this->relativeToPx($usableHeight, $value),
            'w' => fn ($value) => $this->relativeToPx($usableWidth, $value),
            'h' => fn ($value) => $this->relativeToPx($usableHeight, $value),
        ];
    }

    private function resolveFontPath(array $config): string
    {
        $path = $this->resolveAssetPath($config, (string) $config['font']);
        if (!is_file($path)) {
            throw new RuntimeException(sprintf('Fonte da etiqueta nao encontrada (%s).', $path));
        }

        return $path;
    }

    private function resolveBackgroundPath(array $config): ?string
    {
        $path = $this->resolveAssetPath($config, (string) $config['background']);

        return is_file($path) ? $path : null;
    }

    private function resolveAssetPath(array $config, string $filename): string
    {
        $base = $config['assets_dir'];
        if (!is_string($base) || $base === '') {
            $base = '/public/etiqueta';
        }

        if (!str_starts_with($base, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $base)) {
            $base = dirname(__DIR__, 3) . $base;
        }

        return rtrim($base, '\\/') . DIRECTORY_SEPARATOR . ltrim($filename, '\\/');
    }

    private function resolveOutputPath(array $config, string $filename): string
    {
        $dir = $config['output_dir'] ?? '/public/etiqueta/out';

        if (!is_string($dir) || $dir === '') {
            $dir = '/public/etiqueta/out';
        }

        if (!str_starts_with($dir, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:\\\\/', $dir)) {
            $dir = dirname(__DIR__, 3) . $dir;
        }

        return rtrim($dir, '\\/') . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * @return array<string, string>
     */
    private function formatWhatsApp(string $raw): array
    {
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === null) {
            $digits = '';
        }

        $ddd = '';
        $number = $digits;

        if (strlen($digits) >= 10) {
            $ddd = '(' . substr($digits, 0, 2) . ')';
            $number = substr($digits, 2);
        }

        if (strlen($number) === 9) {
            $number = substr($number, 0, 5) . '-' . substr($number, 5);
        } elseif (strlen($number) === 8) {
            $number = substr($number, 0, 4) . '-' . substr($number, 4);
        }

        return [
            'ddd' => $ddd,
            'number' => $number,
        ];
    }

    /**
     * @param array<string, mixed> $layout
     * @param array{x: callable, y: callable, w: callable} $metrics
     */
    private function drawQrCode(GdImage $image, array $layout, array $metrics, string $url): void
    {
        $size = isset($layout['size_px']) ? (int) $layout['size_px'] : 100;
        if ($size <= 0) {
            $size = 100;
        }

        $margin = isset($layout['margin_modules']) ? (int) $layout['margin_modules'] : 0;
        $ec = isset($layout['ec_level']) ? (string) $layout['ec_level'] : 'H';

        $qr = Builder::create()
            ->writer(new PngWriter())
            ->data($url)
            ->encoding(new Encoding('UTF-8'))
            ->errorCorrectionLevel($this->resolveErrorCorrection($ec))
            ->size($size)
            ->margin($margin)
            ->roundBlockSizeMode(new RoundBlockSizeModeMargin())
            ->foregroundColor(new Color(255, 255, 255))
            ->backgroundColor(new Color(0, 0, 0))
            ->build();

        $qrImage = @imagecreatefromstring($qr->getString());
        if (!$qrImage instanceof GdImage) {
            throw new RuntimeException('Falha ao gerar QR Code.');
        }

        $cx = $metrics['x']($layout['x']);
        $cy = $metrics['y']($layout['y']);
        $dstX = $cx - (int) round($size / 2);
        $dstY = $cy - (int) round($size / 2);

        imagecopy($image, $qrImage, $dstX, $dstY, 0, 0, $size, $size);
        imagedestroy($qrImage);
    }

    /**
     * @param array<int> $rgb
     */
    private function allocateColor(GdImage $image, array $rgb): int
    {
        return imagecolorallocate($image, (int) $rgb[0], (int) $rgb[1], (int) $rgb[2]);
    }

    private function drawText(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $sizePt,
        int $color,
        string $fontPath,
        string $align = 'left',
        ?int $maxWidth = null
    ): void {
        $lines = $this->wrapText($text, $sizePt, $fontPath, $maxWidth);
        $lineHeight = (int) round($sizePt * 1.15);

        foreach ($lines as $index => $line) {
            $bbox = imagettfbbox($sizePt, 0, $fontPath, $line);
            if ($bbox === false) {
                continue;
            }

            $width = $bbox[2] - $bbox[0];
            $tx = $x;

            if ($align === 'center') {
                $tx = $x - (int) round($width / 2);
            } elseif ($align === 'right') {
                $tx = $x - $width;
            }

            $ty = $y + ($index * $lineHeight);
            imagettftext($image, $sizePt, 0, $tx, $ty, $color, $fontPath, $line);
        }
    }

    private function drawSingleLineText(
        GdImage $image,
        string $text,
        int $x,
        int $y,
        int $sizePt,
        int $color,
        string $fontPath,
        string $align = 'left'
    ): void {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $bbox = imagettfbbox($sizePt, 0, $fontPath, $text);
        if ($bbox === false) {
            return;
        }

        $width = $bbox[2] - $bbox[0];
        $tx = $x;

        if ($align === 'center') {
            $tx = $x - (int) round($width / 2);
        } elseif ($align === 'right') {
            $tx = $x - $width;
        }

        imagettftext($image, $sizePt, 0, $tx, $y, $color, $fontPath, $text);
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, int $sizePt, string $fontPath, ?int $maxWidth): array
    {
        $text = trim($text);
        if ($text === '' || $maxWidth === null) {
            return [$text];
        }

        $words = preg_split('/\s+/', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $trial = trim($current === '' ? $word : $current . ' ' . $word);
            $bbox = imagettfbbox($sizePt, 0, $fontPath, $trial);
            if ($bbox === false) {
                continue;
            }

            $width = $bbox[2] - $bbox[0];
            if ($width > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        if ($lines === []) {
            $lines[] = $text;
        }

        return $lines;
    }

    private function formatDisplayName(string $fullName): string
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $fullName));
        if ($normalized === '') {
            return '';
        }

        $tokens = preg_split('/\s+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($tokens) || $tokens === []) {
            return $normalized;
        }

        if (count($tokens) <= self::NAME_ABBREVIATE_THRESHOLD) {
            return $normalized;
        }

        $normalizeToken = static function (string $token): string {
            $lower = mb_strtolower($token, 'UTF-8');
            $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
            if ($transliterated !== false) {
                $lower = $transliterated;
            }

            $lower = preg_replace("/[^a-z']+/u", '', $lower);

            return $lower ?? '';
        };

        $shouldKeep = static function (string $token) use ($normalizeToken): bool {
            $normalized = $normalizeToken($token);
            return in_array($normalized, self::NAME_DO_NOT_ABBREVIATE, true);
        };

        $lastIndex = count($tokens) - 1;
        $startLastBlock = $lastIndex;
        while ($startLastBlock - 1 >= 1) {
            $previous = $tokens[$startLastBlock - 1];
            if ($shouldKeep($previous)) {
                $startLastBlock--;
            } else {
                break;
            }
        }

        $isInLastBlock = static fn (int $index) => $index >= $startLastBlock;

        $abbreviate = static function (string $word): string {
            if (mb_strlen($word, 'UTF-8') <= self::NAME_MIN_KEEP) {
                return $word;
            }

            if (mb_strpos($word, '-', 0, 'UTF-8') !== false) {
                $parts = explode('-', $word);
                foreach ($parts as $idx => $part) {
                    $parts[$idx] = (mb_strlen($part, 'UTF-8') > self::NAME_MIN_KEEP)
                        ? mb_substr($part, 0, 1, 'UTF-8') . '.'
                        : $part;
                }

                return implode('-', $parts);
            }

            return mb_substr($word, 0, 1, 'UTF-8') . '.';
        };

        $result = [];
        foreach ($tokens as $index => $token) {
            if ($index === 0) {
                $result[] = $token;
                continue;
            }

            if ($isInLastBlock($index)) {
                $result[] = $token;
                continue;
            }

            if ($shouldKeep($token)) {
                $result[] = $token;
                continue;
            }

            $result[] = $abbreviate($token);
        }

        return implode(' ', $result);
    }

    private function fitFontSize(
        string $text,
        string $fontPath,
        int $maxWidth,
        int $maxSizePt,
        int $minSizePt
    ): int {
        $text = trim($text);
        if ($text === '') {
            return $minSizePt;
        }

        $size = max($minSizePt, $maxSizePt);

        while ($size >= $minSizePt) {
            $bbox = imagettfbbox($size, 0, $fontPath, $text);
            if ($bbox !== false) {
                $width = $bbox[2] - $bbox[0];
                if ($width <= $maxWidth) {
                    return $size;
                }
            }

            $size--;
        }

        return $minSizePt;
    }

    private function resolveErrorCorrection(string $level): ErrorCorrectionLevelInterface
    {
        return match (strtoupper($level)) {
            'L' => new ErrorCorrectionLevelLow(),
            'M' => new ErrorCorrectionLevelMedium(),
            'Q' => new ErrorCorrectionLevelQuartile(),
            default => new ErrorCorrectionLevelHigh(),
        };
    }

    private function hydrateConfig(): array
    {
        $overrides = $this->settings->get('labels', []);
        if (!is_array($overrides)) {
            $overrides = [];
        }

        $config = array_replace_recursive(self::DEFAULT_CONFIG, $overrides);
        $config['assets_dir'] = $this->normalizePath($config['assets_dir']);
        $config['output_dir'] = $this->normalizePath($config['output_dir']);

        return $config;
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:\\\\/', $path)) {
            return $path;
        }

        return dirname(__DIR__, 3) . $path;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Nao foi possivel criar diretorio %s.', $directory));
        }
    }

    /**
     * @return array{
     *     id: string,
     *     uuid: string,
     *     nome_completo: string,
     *     primeiro_nome: string,
     *     pacote: string,
     *     data: string,
     *     whats: string
     * }
     */
    private function resolveData(string $orderId, string $traceId): array
    {
        try {
            $response = $this->apiClient->fetchOrderDetail($orderId, $traceId);
        } catch (CrmRequestException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw new NotFoundException('Pedido nao encontrado.');
            }

            throw new RuntimeException('Nao foi possivel consultar o pedido no CRM.', 0, $exception);
        } catch (CrmUnavailableException $exception) {
            throw new RuntimeException('CRM indisponivel no momento.', 0, $exception);
        }

        $body = $response['body'] ?? [];
        $order = $this->extractOrderPayload($body);

        if ($order === null) {
            throw new NotFoundException('Pedido nao encontrado.');
        }

        $id = $this->extractOrderId($order);
        $uuid = $this->extractOrderUuid($order, $orderId);
        $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
        $nomeCompleto = $this->normalizeName($customer['name'] ?? '');
        $primeiro = $this->extractFirstName($nomeCompleto);
        $schedule = $this->extractSchedule($order);
        $pacote = $this->extractProductName($order);
        $whats = $this->normalizePhone($customer['whatsapp'] ?? '');

        return [
            'id' => $id,
            'uuid' => $uuid,
            'nome_completo' => $nomeCompleto,
            'primeiro_nome' => $primeiro,
            'pacote' => $pacote,
            'data' => $schedule,
            'whats' => $whats,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractOrderPayload(array $body): ?array
    {
        $payload = $body['data'] ?? $body;

        if (is_array($payload)) {
            if (array_is_list($payload)) {
                $payload = $payload[0] ?? null;
            }
        } else {
            $payload = null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function extractOrderId(array $order): string
    {
        $id = $order['id'] ?? null;

        if (is_numeric($id)) {
            return (string) $id;
        }

        if (is_string($id) && $id !== '') {
            return $id;
        }

        $uuid = $order['uuid'] ?? null;
        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }

        throw new RuntimeException('Pedido sem identificador valido.');
    }

    /**
     * @param array<string, mixed> $order
     */
    private function extractOrderUuid(array $order, string $fallback): string
    {
        $uuid = $order['uuid'] ?? null;

        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }

        if (is_string($fallback) && $fallback !== '') {
            return $fallback;
        }

        throw new RuntimeException('UUID do pedido nao informado.');
    }

    /**
     * @param array<string, mixed> $order
     */
    private function extractProductName(array $order): string
    {
        $items = $order['items'] ?? [];
        if (!is_array($items)) {
            return 'Pacote Indefinido';
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $product = $item['product'] ?? [];
            $name = is_array($product) ? ($product['name'] ?? null) : null;
            if (is_string($name) && trim($name) !== '') {
                return $this->normalizePackageName($name);
            }
        }

        return 'Pacote Indefinido';
    }

    /**
     * @param array<string, mixed> $order
     */
    private function extractSchedule(array $order): string
    {
        $raw = $order['schedule_1'] ?? ($order['schedule_one'] ?? null);
        if (!is_string($raw) || trim($raw) === '') {
            return '--/--/--';
        }

        try {
            $date = new \DateTimeImmutable($raw);
        } catch (\Throwable) {
            return '--/--/--';
        }

        return $date->format('d/m/y');
    }

    private function normalizeName(string $value): string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? 'Cliente' : $trimmed;
    }

    private function extractFirstName(string $value): string
    {
        $parts = preg_split('/\s+/', trim($value));
        if (!is_array($parts) || $parts === []) {
            return $value;
        }

        return $parts[0] ?? $value;
    }

    private function normalizePackageName(string $value): string
    {
        return trim($value) === '' ? 'Pacote Indefinido' : trim($value);
    }

    private function normalizePhone(?string $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $value);

        return $digits ?? '';
    }

    private function mmToPx(float $mm, int $dpi): int
    {
        return (int) round($mm * $dpi / 25.4);
    }

    private function relativeToPx(int $reference, int|string $value): int
    {
        if (is_string($value) && str_ends_with($value, '%')) {
            $percent = (float) substr($value, 0, -1);

            return (int) round($reference * $percent / 100);
        }

        return (int) round($value);
    }

    private function toUpper(string $value): string
    {
        if (function_exists('mb_strtoupper')) {
            return mb_strtoupper($value, 'UTF-8');
        }

        return strtoupper($value);
    }
}







