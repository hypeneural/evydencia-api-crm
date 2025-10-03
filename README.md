# Evy API

API REST construída com Slim 4 e PHP-DI que expõe um conjunto de integrações com o CRM Evydencia e recursos locais persistidos em MySQL. Todas as respostas utilizam um envelope padronizado, incluem correlação de requisições (`Trace-Id`) e respeitam controles de autenticação e rate limiting.

## Sumário

- [Principais funcionalidades](#principais-funcionalidades)
- [Arquitetura e organização](#arquitetura-e-organização)
- [Requisitos](#requisitos)
- [Configuração](#configuração)
- [Execução local](#execução-local)
- [Contrato de resposta](#contrato-de-resposta)
- [Integração com o CRM Evydencia](#integração-com-o-crm-evydencia)
- [Endpoints HTTP](#endpoints-http)
  - [/health](#health)
  - [Recursos do CRM](#recursos-do-crm)
  - [Blacklist de WhatsApp (recurso local)](#blacklist-de-whatsapp-recurso-local)
  - [Agendamentos de postagens (recurso local)](#agendamentos-de-postagens-recurso-local)
- [Banco de dados local](#banco-de-dados-local)
- [Observabilidade, rate limit e headers úteis](#observabilidade-rate-limit-e-headers-úteis)
- [Próximos passos sugeridos](#próximos-passos-sugeridos)

## Principais funcionalidades

- **Envelope consistente** em todas as respostas: `success`, `data`, `meta`, `links`, `trace_id`.
- **Autenticação por API Key** (`X-API-Key`) para todo o namespace `/v1/**`.
- **Query DSL unificada** com aliases, filtros condicionais (`eq`, `like`, `gte`, `lte`), ordenação e projeção de campos.
- **Integração com Evydencia CRM** via cliente Guzzle tipado, incluindo controle de tempo limite, headers automáticos e tratamento estruturado de erros.
- **Recursos locais** persistidos em MySQL: blacklist de WhatsApp e agendamentos de postagens.
- **Cache e ETag** para o listing de agendamentos, utilizando Redis opcionalmente.
- **Idempotência** em `POST /v1/blacklist` via header `Idempotency-Key` para evitar duplicações por número de WhatsApp.
- **Rate limiting** baseado em Redis (por IP + rota) com exposição dos headers `X-RateLimit-*`.
- **Logs estruturados** (Monolog) registrando método, caminho, status, duração e `trace_id` em `var/logs/app.log`.

## Arquitetura e organização

```
app/
  Actions/                # Handlers HTTP (CRM, Blacklist, ScheduledPosts, etc.)
  Application/
    DTO/                  # Objetos de transporte
    Services/             # Camadas de orquestração (CRM + recursos locais)
    Support/              # Utilitários (QueryMapper, ApiResponder, etc.)
  Domain/
    Exception/            # Exceções de domínio
    Repositories/         # Contratos para persistência local
  Infrastructure/
    Cache/                # Adapters (Redis cache, rate limiter)
    Http/                 # Cliente Evydencia
    Persistence/          # Implementações PDO (MySQL) + migrations
    Logging/              # LoggerFactory (Monolog)
  Middleware/             # Autenticação, rate limit, logging
config/
  dependencies.php        # Bindings do container PHP-DI
  middleware.php          # Registradores de middleware e error handler
  routes.php              # Rotas agrupadas por domínio
public/
  index.php               # Front controller (PHP built-in server)
var/logs/                 # Logs de runtime (gitignore)
README.md                 # Este documento
```

## Requisitos

- PHP 8.3+
- Composer 2.8+
- MySQL 8.x (necessário para Blacklist e Scheduled Posts)
- Redis 6+ (opcional; requerido para rate limiting e cache de agendamentos)

## Configuração

1. Copie `.env.example` para `.env`.
2. Defina os valores obrigatórios:
   - `APP_API_KEY`: chave utilizada no header `X-API-Key`.
   - `CRM_BASE_URL`: URL base do CRM (default `https://evydencia.com/api`).
   - `CRM_TOKEN`: token de acesso do Evydencia (valor puro, sem o prefixo `Bearer`).
3. Ajustes opcionais:
   - `DB_*` para conectar ao MySQL local (ex.: `DB_HOST=127.0.0.1`, `DB_DATABASE=evy`).
   - `REDIS_*` para habilitar rate limiting e cache (`REDIS_HOST`, `REDIS_PORT`, `REDIS_PASSWORD`).
   - `RATE_LIMIT_PER_MINUTE` caso deseje alterar a janela padrão (60 req/min).
   - `LOG_*` para customizar canal, caminho e nível dos logs.

## Execução local

```bash
# Instalar dependências
composer install

# Executar com o servidor embutido
php -S 127.0.0.1:8080 -t public

# ou utilize: composer start (target configurado no composer.json)
```

Com Laragon/Apache, aponte o DocumentRoot para `public/` e mantenha o `.htaccess` ativo para roteamento.

## Contrato de resposta

### Sucesso

```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 50,
    "total": 0,
    "count": 0,
    "total_pages": 0,
    "source": "api",
    "elapsed_ms": 7
  },
  "links": {
    "self": "http://localhost:8080/v1/resource",
    "next": null,
    "prev": null
  },
  "trace_id": "f30579a9cdbeff69"
}
```

### Erro (RFC 7807-inspired)

```json
{
  "success": false,
  "error": {
    "code": "unprocessable_entity",
    "message": "Parametros invalidos",
    "errors": [
      { "field": "name", "message": "Nome obrigatorio." }
    ]
  },
  "trace_id": "462941753a7fb274"
}
```

## Integração com o CRM Evydencia

- Headers automáticos: `Accept: application/json`, `Authorization: {CRM_TOKEN}`, `Trace-Id`.
- Timeout padrão: 30s (`crm.timeout`).
- Erros HTTP ≥ 400 disparam `CrmRequestException` com log e mapeamento para 502.
- Falhas de rede/timeouts resultam em `CrmUnavailableException` (502).
- O corpo bruto (`raw`) é preservado quando o upstream retorna conteúdo não JSON.

## Endpoints HTTP

### /health

| Método | Descrição                  | Auth | Body | Fonte |
|--------|----------------------------|------|------|-------|
| GET    | Verifica saúde da API.     | Não  | N/A  | `api` |

### Recursos do CRM

#### /v1/orders/search

- **GET**: proxy para busca de pedidos no CRM.
- Autenticação obrigatória.
- Filtros principais: `filter[status]`, `filter[created_at][gte|lte]`, `customer_email`, `product_slug`, `fetch=all`, paginação (`page`, `per_page`), ordenação (`sort=-created_at`) e projeção (`fields[orders]=id,uuid,status`).
- Suporta `q` e parâmetros pass-through (`order[status]=...`).

#### /v1/orders/{uuid}

- **GET**: detalhe de pedido (`/orders/{uuid}/detail` no CRM). Acrescenta `local_map` se houver dados no MySQL local.

#### /v1/orders/{uuid}/status

- **PUT**: atualiza status via CRM.
- Body: `{ "status": "payment_confirmed", "note": "opcional" }`.
- Valida tamanho de strings, persiste histórico no MySQL opcional (`orders_map`).

#### /v1/reports/sold-items

- **GET**: relatório de itens vendidos.
- Filtros: `item_name`, `item_slug`, `item_ref`, `created_at[gte|lte]`, `fetch=all`, ordenação.

#### /v1/campaigns/schedule

- **GET**: agenda de campanhas no CRM.
- Filtros: `campaign_id`, `contact_phone`, DSL de paginação e ordenação.

### Blacklist de WhatsApp (recurso local)

Persistido na tabela `whatsapp_blacklist`.

| Endpoint | Método | Descrição | Auth |
|----------|--------|-----------|------|
| `/v1/blacklist` | GET | Lista entradas com filtros e paginação. | Sim |
| `/v1/blacklist` | POST | Cria nova entrada (idempotente via `Idempotency-Key`). | Sim |
| `/v1/blacklist/{id}` | GET | Detalha registro pelo `id`. | Sim |
| `/v1/blacklist/{id}` | PUT/PATCH | Atualiza campos parciais. | Sim |
| `/v1/blacklist/{id}` | DELETE | Remove registro. | Sim |

**Filtros suportados** (QueryMapper converte automaticamente):

- `filter[whatsapp]=5511988887777` (normaliza apenas dígitos).
- `filter[name][like]=maria` (busca parcial – case insensitive).
- `filter[has_closed_order][eq]=1`.
- `filter[created_at][gte]=2025-01-01`, `filter[created_at][lte]=2025-01-31`.
- `q=texto` (busca em `name` e `whatsapp`).
- Ordenação (`sort=-created_at`), campos (`fields[blacklist]=id,name,whatsapp`).
- Headers de saída: `X-Total-Count`, `X-Request-Id`.

**POST /v1/blacklist**

```json
{
  "name": "Maria Silva",
  "whatsapp": "11988887777",
  "has_closed_order": true,
  "observation": "Bloqueio solicitado pelo financeiro"
}
```

- `whatsapp` é obrigatório e único (apenas dígitos).
- Header opcional `Idempotency-Key` evita duplicidade acidental.

### Agendamentos de postagens (recurso local)

Persistido na tabela `scheduled_posts`. Apoia ETag e cache (Redis).

| Endpoint | Método | Descrição | Auth |
|----------|--------|-----------|------|
| `/v1/scheduled-posts` | GET | Lista agendamentos com filtros, ordenação, projeção e ETag. | Sim |
| `/v1/scheduled-posts` | POST | Cria agendamento (text/image/video). | Sim |
| `/v1/scheduled-posts/{id}` | GET | Detalha agendamento. | Sim |
| `/v1/scheduled-posts/{id}` | PUT/PATCH | Atualiza campos parciais. | Sim |
| `/v1/scheduled-posts/{id}` | DELETE | Remove agendamento. | Sim |
| `/v1/scheduled-posts/ready` | GET | Retorna posts prontos para disparo (`scheduled_datetime <= NOW()` e `messageId` vazio). | Sim |
| `/v1/scheduled-posts/{id}/mark-sent` | POST | Marca agendamento como enviado (`zaapId`, `messageId`). | Sim |

**Filtros para GET /v1/scheduled-posts**

- `filter[type][eq]=text|image|video`.
- `filter[scheduled_datetime][gte]`, `filter[scheduled_datetime][lte]`.
- `filter[messageId][eq]=null` ou `filter[messageId][eq]=!null`.
- `q=texto` (busca em `message` e `caption`).
- `sort=-scheduled_datetime`, `fields[scheduled_posts]=id,type,scheduled_datetime`.
- Headers adicionais: `ETag`, `Cache-Control`, `X-Total-Count`.
- Envie `If-None-Match` para cache condicional (304 retorna sem corpo).

**POST /v1/scheduled-posts**

- `type` obrigatório (`text`, `image` ou `video`).
- `scheduled_datetime` obrigatório (validado em `America/Sao_Paulo`).
- Campos obrigatórios por tipo:
  - `text`: `message`.
  - `image`: `image_url`.
  - `video`: `video_url`.
- Campos opcionais: `caption`, `zaapId`, `messageId`.

Exemplo (imagem):

```json
{
  "type": "image",
  "image_url": "https://cdn.exemplo.com/post.jpg",
  "caption": "Campanha de Dia das Mães",
  "scheduled_datetime": "2025-05-10 09:00:00"
}
```

**POST /v1/scheduled-posts/{id}/mark-sent**

```json
{
  "zaapId": "3D891B2E6D57308A7C4266EA911E9C16",
  "messageId": "3EB033D81D27B28077223C"
}
```

- `messageId` é obrigatório; `zaapId` opcional.
- Atualiza `updated_at` e invalida o cache.

## Banco de dados local

- Crie o schema `evy` (ou o definido em `DB_DATABASE`).
- Execute a migration `app/Infrastructure/Persistence/Migrations/20251003_create_blacklist_and_scheduled_posts.sql` ou copie as instruções abaixo:

```sql
CREATE TABLE IF NOT EXISTS whatsapp_blacklist (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL,
    has_closed_order TINYINT(1) NOT NULL DEFAULT 0,
    observation TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_whatsapp_blacklist_whatsapp (whatsapp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_posts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type ENUM('text','image','video') NOT NULL,
    message TEXT NULL,
    image_url VARCHAR(255) DEFAULT NULL,
    video_url VARCHAR(255) DEFAULT NULL,
    caption VARCHAR(255) DEFAULT NULL,
    scheduled_datetime DATETIME NOT NULL,
    zaapId VARCHAR(50) DEFAULT NULL,
    messageId VARCHAR(50) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_scheduled_posts_datetime (scheduled_datetime),
    KEY idx_scheduled_posts_message_id (messageId)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

## Observabilidade, rate limit e headers úteis

- `Trace-Id`: todas as respostas incluem o mesmo valor utilizado nos logs (`var/logs/app.log`).
- `X-Request-Id`: exposto em recursos locais (Blacklist/Scheduled Posts) para facilitar rastreio.
- Rate limiting (`RedisRateLimiter`) adiciona `X-RateLimit-Limit`, `X-RateLimit-Remaining` e `X-RateLimit-Reset`.
- Logs são gravados em `var/logs/app.log`, contendo método, path, status, duração (ms) e trace_id.
- Em caso de 304 (ETag), a resposta não traz corpo e preserva os cabeçalhos de contexto.

## Próximos passos sugeridos

1. Automatizar testes (unitários/integrados) para QueryMapper, Services e Actions recém adicionados.
2. Disponibilizar um script/migration runner para inicializar as tabelas locais automaticamente.
3. Documentar cenários de idempotência (`Idempotency-Key`) e estratégias de retry na API de Blacklist.
4. Adicionar exemplos de consumo em Postman/Insomnia com coleções já parametrizadas.
5. Considerar docker-compose com PHP + MySQL + Redis para facilitar onboarding.
