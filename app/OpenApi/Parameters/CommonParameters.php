<?php
declare(strict_types=1);

namespace App\OpenApi\Parameters;

use OpenApi\Annotations as OA;

/**
 * @OA\Parameter(
 *     parameter="PageQuery",
 *     name="page",
 *     in="query",
 *     required=false,
 *     description="Page index starting at 1.",
 *     @OA\Schema(type="integer", format="int32", minimum=1, default=1)
 * )
 *
 * @OA\Parameter(
 *     parameter="PerPageQuery",
 *     name="per_page",
 *     in="query",
 *     required=false,
 *     description="Items per page (1-200).",
 *     @OA\Schema(type="integer", format="int32", minimum=1, maximum=200, default=50)
 * )
 *
 * @OA\Parameter(
 *     parameter="FetchStrategyQuery",
 *     name="fetch",
 *     in="query",
 *     required=false,
 *     description="Use value 'all' to fetch every page from the upstream CRM.",
 *     @OA\Schema(type="string", enum={"all"})
 * )
 *
 * @OA\Parameter(
 *     parameter="SortQuery",
 *     name="sort",
 *     in="query",
 *     required=false,
 *     description="Comma separated sort definition (ex.: '-created_at,status').",
 *     @OA\Schema(type="string")
 * )
 *
 * @OA\Parameter(
 *     parameter="IfNoneMatchHeader",
 *     name="If-None-Match",
 *     in="header",
 *     required=false,
 *     description="Provide a previously returned ETag to leverage conditional caching.",
 *     @OA\Schema(type="string")
 * )
 *
 * @OA\Parameter(
 *     parameter="TraceIdHeader",
 *     name="Trace-Id",
 *     in="header",
 *     required=false,
 *     description="Optional trace identifier to correlate logs across services.",
 *     @OA\Schema(type="string", example="a1b2c3d4e5f6a7b8")
 * )
 *
 * @OA\Parameter(
 *     parameter="AuthorizationHeader",
 *     name="Authorization",
 *     in="header",
 *     required=false,
 *     description="JWT bearer token issued by Evydencia Auth. Format: 'Bearer <token>'.",
 *     @OA\Schema(type="string", example="Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...")
 * )
 */
final class CommonParameters
{
}
