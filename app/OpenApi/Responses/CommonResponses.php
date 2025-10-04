<?php
declare(strict_types=1);

namespace App\OpenApi\Responses;

use OpenApi\Annotations as OA;

/**
 * @OA\Response(
 *     response="UnauthorizedError",
 *     description="Authentication required or token invalid.",
 *     @OA\Header(header="WWW-Authenticate", description="Auth challenge details.", @OA\Schema(type="string")),
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 *
 * @OA\Response(
 *     response="ForbiddenError",
 *     description="Caller lacks permission to perform the operation.",
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 *
 * @OA\Response(
 *     response="ValidationError",
 *     description="Payload or query parameters failed validation.",
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 *
 * @OA\Response(
 *     response="TooManyRequestsError",
 *     description="Rate limit exceeded.",
 *     @OA\Header(header="Retry-After", description="Seconds until retry is allowed.", @OA\Schema(type="integer", format="int32", example=30)),
 *     @OA\Header(header="RateLimit-Limit", description="Bucket limit.", @OA\Schema(type="integer", example=120)),
 *     @OA\Header(header="RateLimit-Remaining", description="Requests left in the current window.", @OA\Schema(type="integer", example=0)),
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 *
 * @OA\Response(
 *     response="NotFoundError",
 *     description="Resource not found.",
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 *
 * @OA\Response(
 *     response="BadGatewayError",
 *     description="Upstream integration failed.",
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 *
 * @OA\Response(
 *     response="InternalServerError",
 *     description="Unexpected failure on Evydencia side.",
 *     @OA\JsonContent(ref="#/components/schemas/ErrorEnvelope")
 * )
 */
final class CommonResponses
{
}
