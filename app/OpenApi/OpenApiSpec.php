<?php
declare(strict_types=1);

namespace App\OpenApi;

/**
 * @OA\Info(
 *     title="Evydência API",
 *     version="1.0.0",
 *     description="API pública da plataforma Evydência. Documentação gerada automaticamente via swagger-php."
 * )
 *
 * @OA\Server(
 *     url="http://localhost:8080",
 *     description="Ambiente local"
 * )
 *
 * @OA\Server(
 *     url="https://api.evydencia.com",
 *     description="Produção"
 * )
 *
 * @OA\SecurityScheme(
 *     securityScheme="ApiKeyAuth",
 *     type="apiKey",
 *     in="header",
 *     name="X-API-Key",
 *     description="Chave de acesso distribuída pelo time. Inclua 'X-API-Key: {token}' em todas as requisições protegidas."
 * )
 *
 * @OA\SecurityRequirement(name="ApiKeyAuth")
 *
 * @OA\Tag(name="Health", description="Monitoramento e status do serviço")
 * @OA\Tag(name="Blacklist", description="Gestão de contatos bloqueados para WhatsApp")
 * @OA\Tag(name="ScheduledPosts", description="Agendamento e gerenciamento de envios automáticos")
 * @OA\Tag(name="Orders", description="Consulta e atualização de pedidos no CRM")
 * @OA\Tag(name="WhatsApp", description="Integração com o provedor Z-API para disparos")
 * @OA\Tag(name="Reports", description="Relatórios operacionais e exportações")
 * @OA\Tag(name="Campaigns", description="Programação e controle de campanhas")
 */
final class OpenApiSpec
{
}

