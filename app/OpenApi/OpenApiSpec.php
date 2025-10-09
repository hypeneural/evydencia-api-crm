<?php
declare(strict_types=1);

namespace App\OpenApi;

use OpenApi\Annotations as OA;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         title="Evydencia API",
 *         version="1.1.0",
 *         description="HTTP interface for Evydencia platform. Documentation generated with swagger-php.",
 *         termsOfService="https://evydencia.com/terms",
 *         @OA\Contact(name="Evydencia Platform", email="devs@evydencia.com"),
 *         @OA\License(name="Proprietary", url="https://evydencia.com/license")
 *     ),
 *     @OA\Server(
 *         url="https://api.evydencia.com/v1",
 *         description="Production cluster"
 *     ),
 *     @OA\Server(
 *         url="https://staging-api.evydencia.com/v1",
 *         description="Staging mirror for QA and partners"
 *     ),
 *     @OA\Server(
 *         url="http://localhost:8080/v1",
 *         description="Local sandbox via Docker or Sail"
 *     ),
 *     @OA\Components(
 *         @OA\SecurityScheme(
 *             securityScheme="BearerAuth",
 *             type="http",
 *             scheme="bearer",
 *             bearerFormat="JWT",
 *             description="Send Authorization: Bearer {token}. Tokens are issued by Evydencia Auth service."
 *         ),
 *         @OA\SecurityScheme(
 *             securityScheme="ApiKeyLegacy",
 *             type="apiKey",
 *             in="header",
 *             name="X-API-Key",
 *             description="Legacy key header kept for backward compatibility."
 *         )
 *     ),
 *     security={{"BearerAuth": {}}, {"ApiKeyLegacy": {}}},
 *     @OA\Tag(name="Health", description="Service status and platform uptime checks"),
 *     @OA\Tag(name="Blacklist", description="Manage blocked WhatsApp contacts"),
 *     @OA\Tag(name="ScheduledPosts", description="Plan and monitor scheduled outbound messages"),
 *     @OA\Tag(name="Orders", description="CRM order search and lifecycle operations"),
 *     @OA\Tag(name="Labels", description="Geracao de etiquetas para pedidos"),
 *     @OA\Tag(name="WhatsApp", description="WhatsApp bridge powered by Z-API"),
 *     @OA\Tag(name="Reports", description="Operational reports and exports"),
 *     @OA\Tag(name="Campaigns", description="Campaign scheduling and orchestration"),
 *     @OA\Tag(name="Passwords", description="Gestao de senhas e credenciais empresariais"),
 *     @OA\ExternalDocumentation(
 *         description="Guides, SDKs and onboarding",
 *         url="https://docs.evydencia.com"
 *     )
 * )
 */
final class OpenApiSpec
{
}
