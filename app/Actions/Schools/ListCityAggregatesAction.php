<?php

declare(strict_types=1);

namespace App\Actions\Schools;

use App\Application\Services\SchoolService;
use App\Application\Support\ApiResponder;
use OpenApi\Annotations as OA;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class ListCityAggregatesAction
{
    public function __construct(
        private readonly SchoolService $service,
        private readonly ApiResponder $responder
    ) {
    }

    /**
     * @OA\Get(
     *     path="/v1/cidades",
     *     tags={"Escolas"},
     *     summary="Lista cidades com agregadores de escolas",
     *     @OA\Parameter(
     *         name="includeBairros",
     *         in="query",
     *         description="Quando true inclui agregados dos bairros dentro da resposta",
     *         required=false,
     *         @OA\Schema(type="boolean")
     *     ),
     *     @OA\Response(response=200, description="Sucesso"),
     *     @OA\Response(response=500, description="Erro interno")
     * )
     */
    public function __invoke(Request $request, Response $response): Response
    {
        $traceId = bin2hex(random_bytes(8));
        $query = $request->getQueryParams();

        $includeNeighborhoods = $this->toBool($query['includeBairros'] ?? false);
        $filters = $query;
        unset($filters['includeBairros']);

        $data = $this->service->listCityAggregates($filters, $includeNeighborhoods);

        $etag = $this->service->makeAggregatesEtag(
            $data,
            $filters,
            'city-aggregates',
            $includeNeighborhoods
        );

        $cacheControl = $this->determineCacheControl($request);
        if ($etag !== null && $this->etagMatches($request->getHeaderLine('If-None-Match'), $etag)) {
            return $response
                ->withStatus(304)
                ->withHeader('ETag', $this->formatEtag($etag))
                ->withHeader('Cache-Control', $cacheControl);
        }

        $payload = $this->responder->successList(
            $response,
            $data,
            [
                'count' => count($data),
                'source' => 'database',
                'extra' => [
                    'include_bairros' => $includeNeighborhoods,
                    'etag' => $etag,
                ],
            ],
            [
                'self' => (string) $request->getUri(),
            ],
            $traceId
        );

        if ($etag !== null) {
            $payload = $payload->withHeader('ETag', $this->formatEtag($etag));
        }

        return $payload->withHeader('Cache-Control', $cacheControl);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '' || $normalized === '0' || $normalized === 'false' || $normalized === 'no') {
                return false;
            }

            if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes') {
                return true;
            }
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return (bool) $value;
    }

    private function etagMatches(string $header, string $etag): bool
    {
        if ($header === '') {
            return false;
        }

        $normalized = trim($header, "\" \t");
        return $normalized === $etag;
    }

    private function formatEtag(string $etag): string
    {
        return '"' . $etag . '"';
    }

    private function determineCacheControl(Request $request): string
    {
        $clientHeader = strtolower($request->getHeaderLine('X-Client-Type'));
        $clientQuery = strtolower((string) ($request->getQueryParams()['client'] ?? ''));
        $isMobile = $clientHeader === 'mobile' || $clientQuery === 'mobile';

        return $isMobile
            ? 'private, max-age=120, stale-while-revalidate=300'
            : 'private, max-age=30, must-revalidate';
    }
}
