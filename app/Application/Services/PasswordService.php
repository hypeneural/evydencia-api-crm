<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Application\DTO\QueryOptions;
use App\Application\Support\PasswordCrypto;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\ValidationException;
use App\Domain\Repositories\PasswordRepositoryInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class PasswordService
{
    private const TYPES = ['Sistema', 'Rede Social', 'E-mail'];
    private const BULK_ACTION_VERIFY = 'verify';
    private const BULK_ACTION_UNVERIFY = 'unverify';
    private const BULK_ACTION_DELETE = 'delete';

    public function __construct(
        private readonly PasswordRepositoryInterface $repository,
        private readonly PasswordCrypto $crypto,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     meta: array<string, mixed>,
     *     total: int,
     *     max_updated_at: string|null
     * }
     */
    public function list(QueryOptions $options, string $traceId): array
    {
        $criteria = $options->crmQuery;
        $filters = is_array($criteria['filters'] ?? null) ? $criteria['filters'] : [];
        $search = isset($criteria['search']) && is_string($criteria['search']) ? trim($criteria['search']) : null;

        $result = $this->repository->search(
            $filters,
            $search,
            $options->page,
            $options->perPage,
            $options->fetchAll,
            $options->sort
        );

        $items = $result['items'] ?? [];
        $total = (int) ($result['total'] ?? 0);
        $maxUpdatedAt = $result['max_updated_at'] ?? null;

        $fields = $this->resolveFields($options->fields);
        if ($fields !== []) {
            $items = array_map(
                fn (array $row): array => $this->projectFields($row, $fields),
                $items
            );
        }

        $count = count($items);
        $page = $options->fetchAll ? 1 : $options->page;
        $perPage = $options->fetchAll ? ($count > 0 ? $count : $options->perPage) : $options->perPage;
        $totalPages = $options->fetchAll
            ? 1
            : ($perPage > 0 ? (int) ceil($total / $perPage) : 1);

        $meta = [
            'page' => $page,
            'per_page' => $perPage,
            'count' => $count,
            'total' => $total,
            'total_pages' => $totalPages,
            'source' => 'database',
        ];

        return [
            'data' => $items,
            'meta' => $meta,
            'total' => $total,
            'max_updated_at' => $maxUpdatedAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(
        string $id,
        bool $decryptPassword = false,
        ?string $userId = null,
        ?string $originIp = null,
        ?string $userAgent = null
    ): array
    {
        $resource = $this->repository->findById($id);
        if ($resource === null) {
            throw new NotFoundException('Senha nao encontrada.');
        }

        if ($decryptPassword) {
            try {
                $resource['senha'] = $this->crypto->decrypt((string) $resource['senha']);
            } catch (Throwable $exception) {
                $this->logger->error('passwords.decrypt.failed', [
                    'trace' => $exception->getMessage(),
                    'password_id' => $id,
                ]);

                throw new RuntimeException('Nao foi possivel descriptografar a senha solicitada.');
            }
        }

        $action = $decryptPassword ? 'password_shown' : 'viewed';
        $this->repository->logAction(
            $id,
            $action,
            $userId,
            $originIp,
            $userAgent,
            $this->maskSecretFields($resource),
            $this->maskSecretFields($resource)
        );

        return $resource;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(array $payload, string $traceId, ?string $userId, ?string $originIp, ?string $userAgent): array
    {
        [$data, $errors] = $this->validatePayload($payload, true);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $plainPassword = (string) $data['senha_plain'];
        unset($data['senha_plain']);

        try {
            $encryptedPassword = $this->crypto->encrypt($plainPassword);
        } catch (Throwable $exception) {
            $this->logger->error('passwords.encrypt.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);
            throw new RuntimeException('Nao foi possivel criptografar a senha informada.');
        }

        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        $createPayload = [
            'usuario' => $data['usuario'],
            'senha' => $encryptedPassword,
            'link' => $data['link'],
            'tipo' => $data['tipo'],
            'local' => $data['local'],
            'verificado' => $data['verificado'],
            'ativo' => true,
            'descricao' => $data['descricao'],
            'ip' => $data['ip'],
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $userId,
            'updated_by' => $userId,
        ];

        try {
            $resource = $this->repository->create($createPayload);
        } catch (Throwable $exception) {
            $this->logger->error('passwords.create.failed', [
                'trace_id' => $traceId,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Nao foi possivel criar a senha.');
        }

        $this->repository->logAction(
            (string) ($resource['id'] ?? ''),
            'created',
            $userId,
            $originIp,
            $userAgent,
            [],
            $this->maskSecretFields($resource)
        );

        return $resource;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(
        string $id,
        array $payload,
        string $traceId,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent
    ): array {
        $current = $this->repository->findById($id);
        if ($current === null || (isset($current['ativo']) && (int) $current['ativo'] === 0)) {
            throw new NotFoundException('Senha nao encontrada.');
        }

        [$data, $errors] = $this->validatePayload($payload, false);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $updatePayload = [];

        if (array_key_exists('usuario', $data)) {
            $updatePayload['usuario'] = $data['usuario'];
        }
        if (array_key_exists('link', $data)) {
            $updatePayload['link'] = $data['link'];
        }
        if (array_key_exists('tipo', $data)) {
            $updatePayload['tipo'] = $data['tipo'];
        }
        if (array_key_exists('local', $data)) {
            $updatePayload['local'] = $data['local'];
        }
        if (array_key_exists('verificado', $data)) {
            $updatePayload['verificado'] = $data['verificado'];
        }
        if (array_key_exists('descricao', $data)) {
            $updatePayload['descricao'] = $data['descricao'];
        }
        if (array_key_exists('ip', $data)) {
            $updatePayload['ip'] = $data['ip'];
        }

        if (array_key_exists('senha_plain', $data)) {
            try {
                $updatePayload['senha'] = $this->crypto->encrypt((string) $data['senha_plain']);
            } catch (Throwable $exception) {
                $this->logger->error('passwords.encrypt.failed', [
                    'trace_id' => $traceId,
                    'password_id' => $id,
                    'error' => $exception->getMessage(),
                ]);

                throw new RuntimeException('Nao foi possivel criptografar a senha informada.');
            }
        }

        if ($updatePayload === []) {
            return $current;
        }

        $updatePayload['updated_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $updatePayload['updated_by'] = $userId;

        try {
            $resource = $this->repository->update($id, $updatePayload);
        } catch (Throwable $exception) {
            $this->logger->error('passwords.update.failed', [
                'trace_id' => $traceId,
                'password_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Nao foi possivel atualizar a senha.');
        }

        $this->repository->logAction(
            $id,
            'updated',
            $userId,
            $originIp,
            $userAgent,
            $this->maskSecretFields($current),
            $this->maskSecretFields($resource)
        );

        return $resource;
    }

    public function delete(string $id, string $traceId, ?string $userId, ?string $originIp, ?string $userAgent): void
    {
        $current = $this->repository->findById($id);
        if ($current === null || (isset($current['ativo']) && (int) $current['ativo'] === 0)) {
            throw new NotFoundException('Senha nao encontrada.');
        }

        try {
            $deleted = $this->repository->softDelete($id, $userId);
        } catch (Throwable $exception) {
            $this->logger->error('passwords.delete.failed', [
                'trace_id' => $traceId,
                'password_id' => $id,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Nao foi possivel excluir a senha.');
        }

        if (!$deleted) {
            throw new RuntimeException('Nao foi possivel excluir a senha.');
        }

        $this->repository->logAction(
            $id,
            'deleted',
            $userId,
            $originIp,
            $userAgent,
            $this->maskSecretFields($current),
            []
        );
    }

    /**
     * @param array<int, string> $ids
     * @return array<string, mixed>
     */
    public function bulkAction(
        string $action,
        array $ids,
        string $traceId,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent
    ): array {
        $action = strtolower(trim($action));
        if (!in_array($action, [self::BULK_ACTION_VERIFY, self::BULK_ACTION_UNVERIFY, self::BULK_ACTION_DELETE], true)) {
            throw new ValidationException([['field' => 'action', 'message' => 'Acao invalida.']]);
        }

        $uniqueIds = array_values(array_filter(array_unique(array_map('trim', $ids)), static fn (string $value): bool => $value !== ''));
        if ($uniqueIds === []) {
            throw new ValidationException([['field' => 'ids', 'message' => 'Informe pelo menos um id valido.']]);
        }

        $results = [];
        $affected = 0;

        foreach ($uniqueIds as $id) {
            try {
                $result = $this->processBulkItem($action, $id, $userId, $originIp, $userAgent);
                $results[] = ['id' => $id, 'success' => true];
                if ($result) {
                    $affected++;
                }
            } catch (NotFoundException $exception) {
                $results[] = [
                    'id' => $id,
                    'success' => false,
                    'error' => $exception->getMessage(),
                ];
            } catch (Throwable $exception) {
                $this->logger->error('passwords.bulk.failed', [
                    'trace_id' => $traceId,
                    'password_id' => $id,
                    'action' => $action,
                    'error' => $exception->getMessage(),
                ]);
                $results[] = [
                    'id' => $id,
                    'success' => false,
                    'error' => 'Falha ao processar o registro.',
                ];
            }
        }

        $failed = count(array_filter($results, static fn (array $row): bool => $row['success'] === false));

        return [
            'action' => $action,
            'affected_rows' => $affected,
            'processed' => count($results),
            'failed' => $failed,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function stats(array $filters): array
    {
        try {
            return $this->repository->stats($filters);
        } catch (Throwable $exception) {
            $this->logger->error('passwords.stats.failed', [
                'error' => $exception->getMessage(),
                'filters' => $filters,
            ]);

            throw new RuntimeException('Nao foi possivel recuperar as estatisticas.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array{local: string, count: int}>
     */
    public function platforms(array $filters, int $limit, ?int $minCount): array
    {
        try {
            return $this->repository->platforms($filters, $limit, $minCount);
        } catch (Throwable $exception) {
            $this->logger->error('passwords.platforms.failed', [
                'error' => $exception->getMessage(),
                'filters' => $filters,
            ]);

            throw new RuntimeException('Nao foi possivel listar as plataformas.');
        }
    }

    /**
     * @param array<string, mixed> $filters
     * @param array<int, array{field: string, direction: string}> $sort
     * @return array{stream: StreamInterface, content_type: string, filename: string}
     */
    public function export(array $filters, ?string $search, array $sort, string $format): array
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['json', 'csv', 'xlsx'], true)) {
            throw new ValidationException([['field' => 'format', 'message' => 'Formato invalido.']]);
        }

        return match ($format) {
            'json' => $this->exportJson(iterator_to_array($this->repository->export($filters, $search, $sort), false)),
            'csv' => $this->exportCsv($this->repository->export($filters, $search, $sort)),
            'xlsx' => $this->exportXlsx($this->repository->export($filters, $search, $sort)),
            default => throw new RuntimeException('Formato nao suportado.'),
        };
    }

    /**
     * @return array{exists: bool, password: array<string, mixed>|null}
     */
    public function check(string $local, string $usuario): array
    {
        $local = trim($local);
        $usuario = trim($usuario);

        if ($local === '' || $usuario === '') {
            throw new ValidationException([['field' => 'local', 'message' => 'local e usuario obrigatorios.']]);
        }

        $resource = $this->repository->findByLocalAndUsuario($local, $usuario);

        if ($resource === null) {
            return ['exists' => false, 'password' => null];
        }

        return [
            'exists' => true,
            'password' => [
                'id' => $resource['id'],
                'usuario' => $resource['usuario'],
                'local' => $resource['local'],
                'verificado' => (bool) $resource['verificado'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function exportJson(array $options): array
    {
        $payload = [
            'success' => true,
            'data' => $options,
            'meta' => [
                'format' => 'json',
                'total_exported' => count($options),
            ],
            'trace_id' => bin2hex(random_bytes(8)),
        ];

        $stream = Utils::streamFor(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'stream' => $stream,
            'content_type' => 'application/json',
            'filename' => sprintf('passwords_%s.json', date('Y-m-d')),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function exportCsv(iterable $rows): array
    {
        $handle = fopen('php://temp', 'w+b');
        if ($handle === false) {
            throw new RuntimeException('Nao foi possivel preparar o arquivo para exportacao.');
        }

        $headers = [
            'id',
            'usuario',
            'link',
            'tipo',
            'local',
            'verificado',
            'descricao',
            'ip',
            'created_at',
            'updated_at',
            'created_by',
            'updated_by',
        ];

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $value = $row[$header] ?? '';
                if (is_bool($value)) {
                    $value = $value ? '1' : '0';
                }
                $line[] = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $stream = Utils::streamFor($handle);

        return [
            'stream' => $stream,
            'content_type' => 'text/csv',
            'filename' => sprintf('passwords_%s.csv', date('Y-m-d')),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function exportXlsx(iterable $rows): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Extensao ZipArchive nao disponivel para exportar XLSX.');
        }

        $headers = [
            'ID',
            'Usuario',
            'Link',
            'Tipo',
            'Local',
            'Verificado',
            'Descricao',
            'IP',
            'Criado em',
            'Atualizado em',
            'Criado por',
            'Atualizado por',
        ];

        $sheetRows = [];
        $sheetRows[] = $headers;

        foreach ($rows as $row) {
            $sheetRows[] = [
                (string) ($row['id'] ?? ''),
                (string) ($row['usuario'] ?? ''),
                (string) ($row['link'] ?? ''),
                (string) ($row['tipo'] ?? ''),
                (string) ($row['local'] ?? ''),
                (isset($row['verificado']) && (int) $row['verificado'] === 1) ? 'Sim' : 'Nao',
                (string) ($row['descricao'] ?? ''),
                (string) ($row['ip'] ?? ''),
                (string) ($row['created_at'] ?? ''),
                (string) ($row['updated_at'] ?? ''),
                (string) ($row['created_by'] ?? ''),
                (string) ($row['updated_by'] ?? ''),
            ];
        }

        $xmlRows = '';
        foreach ($sheetRows as $rowIndex => $values) {
            $rowNumber = $rowIndex + 1;
            $xmlCells = '';
            foreach ($values as $colIndex => $value) {
                $columnLetter = $this->columnLetter($colIndex + 1);
                $escaped = htmlspecialchars((string) $value, ENT_XML1);
                $xmlCells .= sprintf('<c r="%s%s" t="inlineStr"><is><t>%s</t></is></c>', $columnLetter, $rowNumber, $escaped);
            }
            $xmlRows .= sprintf('<row r="%d">%s</row>', $rowNumber, $xmlCells);
        }

        $sheetXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
    <sheetData>
        {$xmlRows}
    </sheetData>
</worksheet>
XML;

        $workbookXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="Senhas" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>
XML;

        $relsXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>
XML;

        $workbookRelsXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML;

        $contentTypesXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML;

        $tempFile = tmpfile();
        if ($tempFile === false) {
            throw new RuntimeException('Nao foi possivel gerar o arquivo XLSX.');
        }

        $meta = stream_get_meta_data($tempFile);
        $tempPath = $meta['uri'] ?? null;
        if ($tempPath === null) {
            throw new RuntimeException('Nao foi possivel gerar o arquivo XLSX.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tempPath, \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Falha ao compor o arquivo XLSX.');
        }

        $zip->addFromString('[Content_Types].xml', $contentTypesXml);
        $zip->addFromString('_rels/.rels', $relsXml);
        $zip->addFromString('xl/workbook.xml', $workbookXml);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
        $zip->close();

        rewind($tempFile);

        $stream = Utils::streamFor($tempFile);

        return [
            'stream' => $stream,
            'content_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'filename' => sprintf('passwords_%s.xlsx', date('Y-m-d')),
        ];
    }

    private function columnLetter(int $index): string
    {
        $result = '';
        while ($index > 0) {
            $index--;
            $result = chr(ord('A') + ($index % 26)) . $result;
            $index = intdiv($index, 26);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<int, array<string, string>>}
     */
    private function validatePayload(array $data, bool $isCreate): array
    {
        $errors = [];
        $result = [];

        if ($isCreate || array_key_exists('usuario', $data)) {
            $usuario = $this->sanitizeString($data['usuario'] ?? null);
            if ($usuario === '' || strlen($usuario) < 3 || strlen($usuario) > 255) {
                $errors[] = ['field' => 'usuario', 'message' => 'Usuario deve ter entre 3 e 255 caracteres.'];
            } else {
                $result['usuario'] = $usuario;
            }
        }

        if ($isCreate || array_key_exists('senha', $data)) {
            $senha = $this->sanitizeString($data['senha'] ?? null);
            if ($senha === '' || strlen($senha) < 6 || strlen($senha) > 255) {
                $errors[] = ['field' => 'senha', 'message' => 'Senha deve ter entre 6 e 255 caracteres.'];
            } else {
                $result['senha_plain'] = $senha;
            }
        }

        if ($isCreate || array_key_exists('link', $data)) {
            $link = $this->sanitizeString($data['link'] ?? null);
            if ($link === '' || !$this->isValidLink($link)) {
                $errors[] = ['field' => 'link', 'message' => 'Link deve ser uma URL valida ou mailto:.'];
            } else {
                $result['link'] = $link;
            }
        }

        if ($isCreate || array_key_exists('tipo', $data)) {
            $tipo = $this->sanitizeString($data['tipo'] ?? null);
            if ($tipo === '' || !in_array($tipo, self::TYPES, true)) {
                $errors[] = ['field' => 'tipo', 'message' => 'Tipo invalido.'];
            } else {
                $result['tipo'] = $tipo;
            }
        }

        if ($isCreate || array_key_exists('local', $data)) {
            $local = $this->sanitizeString($data['local'] ?? null);
            if ($local === '' || strlen($local) < 2 || strlen($local) > 100) {
                $errors[] = ['field' => 'local', 'message' => 'Local deve ter entre 2 e 100 caracteres.'];
            } else {
                $result['local'] = $local;
            }
        }

        if (array_key_exists('verificado', $data)) {
            $result['verificado'] = $this->sanitizeBool($data['verificado']);
        } elseif ($isCreate) {
            $result['verificado'] = false;
        }

        if (array_key_exists('descricao', $data)) {
            $descricao = $this->sanitizeNullableString($data['descricao']);
            if ($descricao !== null && strlen($descricao) > 500) {
                $errors[] = ['field' => 'descricao', 'message' => 'Descricao deve ter no maximo 500 caracteres.'];
            } else {
                $result['descricao'] = $descricao;
            }
        } elseif ($isCreate) {
            $result['descricao'] = null;
        }

        if (array_key_exists('ip', $data)) {
            $ip = $this->sanitizeNullableString($data['ip']);
            if ($ip !== null && !$this->isValidIp($ip)) {
                $errors[] = ['field' => 'ip', 'message' => 'IP invalido.'];
            } else {
                $result['ip'] = $ip;
            }
        } elseif ($isCreate) {
            $result['ip'] = null;
        }

        return [$result, $errors];
    }

    private function sanitizeString(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function sanitizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'sim'], true);
        }

        return false;
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = $this->sanitizeString($value);

        return $string === '' ? null : $string;
    }

    private function isValidLink(string $link): bool
    {
        if (str_starts_with(strtolower($link), 'mailto:')) {
            $email = substr($link, 7);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        }

        return filter_var($link, FILTER_VALIDATE_URL) !== false;
    }

    private function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @param array<string, array<int, string>> $fields
     * @return array<int, string>
     */
    private function resolveFields(array $fields): array
    {
        if ($fields === []) {
            return [];
        }

        if (isset($fields['passwords']) && is_array($fields['passwords'])) {
            return $fields['passwords'];
        }

        if (isset($fields['default']) && is_array($fields['default'])) {
            return $fields['default'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $fields
     * @return array<string, mixed>
     */
    private function projectFields(array $row, array $fields): array
    {
        $projection = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $projection[$field] = $row[$field];
            }
        }

        return $projection;
    }

    /**
     * @param array<string, mixed> $resource
     * @return array<string, mixed>
     */
    private function maskSecretFields(array $resource): array
    {
        if (array_key_exists('senha', $resource)) {
            $resource['senha'] = '[ENCRYPTED]';
        }

        return $resource;
    }

    private function processBulkItem(
        string $action,
        string $id,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent
    ): bool {
        $resource = $this->repository->findById($id);
        if ($resource === null || (isset($resource['ativo']) && (int) $resource['ativo'] === 0)) {
            throw new NotFoundException('Senha nao encontrada.');
        }

        return match ($action) {
            self::BULK_ACTION_VERIFY => $this->markVerification($id, true, $resource, $userId, $originIp, $userAgent),
            self::BULK_ACTION_UNVERIFY => $this->markVerification($id, false, $resource, $userId, $originIp, $userAgent),
            self::BULK_ACTION_DELETE => $this->bulkDelete($id, $resource, $userId, $originIp, $userAgent),
            default => false,
        };
    }

    private function markVerification(
        string $id,
        bool $verified,
        array $resource,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent
    ): bool {
        if ((bool) $resource['verificado'] === $verified) {
            return false;
        }

        $updated = $this->repository->update($id, [
            'verificado' => $verified,
            'updated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'updated_by' => $userId,
        ]);

        $this->repository->logAction(
            $id,
            $verified ? 'verified' : 'unverified',
            $userId,
            $originIp,
            $userAgent,
            $this->maskSecretFields($resource),
            $this->maskSecretFields($updated)
        );

        return true;
    }

    private function bulkDelete(
        string $id,
        array $resource,
        ?string $userId,
        ?string $originIp,
        ?string $userAgent
    ): bool {
        $deleted = $this->repository->softDelete($id, $userId);
        if ($deleted) {
            $this->repository->logAction(
                $id,
                'deleted',
                $userId,
                $originIp,
                $userAgent,
                $this->maskSecretFields($resource),
                []
            );
        }

        return $deleted;
    }
}
