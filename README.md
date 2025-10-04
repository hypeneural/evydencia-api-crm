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
  - [/doc](#doc)
  - [/health](#health)
  - [Recursos do CRM](#recursos-do-crm)
  - [Relatorios dinamicos (CRM)](#relatorios-dinamicos-v1reports)
  - [Campanhas (CRM)](#campanhas-v1campaigns)
  - [Blacklist de WhatsApp (recurso local)](#blacklist-de-whatsapp-recurso-local)
  - [Agendamentos de postagens (recurso local)](#agendamentos-de-postagens-recurso-local)
- [WhatsApp (Z-API)](#whatsapp-z-api)
- [Banco de dados local](#banco-de-dados-local)
- [Observabilidade, rate limit e headers úteis](#observabilidade-rate-limit-e-headers-úteis)
- [Próximos passos sugeridos](#próximos-passos-sugeridos)

## Principais funcionalidades

- **Envelope consistente** em todas as respostas: `success`, `data`, `meta`, `links`, `trace_id`.
- **Autenticação por API Key** (`X-API-Key`) para todo o namespace `/v1/**`.
- **Query DSL unificada** com aliases, filtros condicionais (`eq`, `like`, `gte`, `lte`), ordenação e projeção de campos.
- **Integração com Evydencia CRM** via cliente Guzzle tipado, incluindo controle de tempo limite, headers automáticos e tratamento estruturado de erros.
- **Relatorios dinamicos** (ReportEngine) com cache opcional (Redis), exportacao CSV/JSON e validacao por relatorio.
- **Recursos locais** persistidos em MySQL: blacklist de WhatsApp e agendamentos de postagens.
- **Integração WhatsApp (Z-API)** para envio de textos, áudios, imagens, documentos e status.
- **Cache e ETag** para o listing de agendamentos, utilizando Redis opcionalmente.
- **Idempotência** em `POST /v1/blacklist` via header `Idempotency-Key` para evitar duplicações por número de WhatsApp.
- **Rate limiting** baseado em Redis (por IP + rota) com exposição dos headers `X-RateLimit-*`.
- **Logs estruturados** (Monolog) registrando método, caminho, status, duração e `trace_id` em `var/logs/app.log`.
- **Documentacao OpenAPI** acessível via `/doc`, gerada com `composer openapi:build` (Swagger UI + JSON).

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
   - `ZAPI_*` para integração com a Z-API (`ZAPI_BASE_URL`, `ZAPI_INSTANCE`, `ZAPI_TOKEN`, `ZAPI_CLIENT_TOKEN`, `ZAPI_TIMEOUT`).
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

### /doc

Serviço da documentação OpenAPI (Swagger UI + OpenAPI JSON).

- **GET**: retorna `public/docs/index.html` carregando `public/openapi.json`.
- Não exige autenticação e envia `Cache-Control: no-store, no-cache`.
- Execute `composer openapi:build` após alterar Actions/Schemas para reconstruir os artefatos.

### /health

| Método | Descrição                  | Auth | Body | Fonte |
|--------|----------------------------|------|------|-------|
| GET    | Verifica saúde da API.     | Não  | N/A  | `api` |

### Recursos do CRM

#### Pedidos (/v1/orders)

**GET /v1/orders/search**

- Proxy para busca de pedidos no CRM com mapeamento de filtros amigáveis para os parâmetros nativos (`order[...]`, `customer[...]`, `product[...]`).
- Autenticação obrigatória via `X-API-Key`.
- Aliases aceitos: `filter[order_id]`, `filter[uuid]`, `filter[status]`, `filter[customer_*]`, `filter[product_*]`, `filter[created_at][gte|lte]`, `filter[session_at][gte|lte]`, `filter[selection_at][gte|lte]`, `filter[customer_name][like]`.
- Pass-through direto: `order[id|uuid|status|created-*|session-*|selection-*]`, `customer[...]`, `product[...]`, `include`, `fields[orders]`, `q`.
- Suporta `page`/`per_page`, ordenação (`sort=-created_at`), projeção (`fields[orders]=uuid,status,total`), paginação automática (`fetch=all`) e `links` com URLs do CRM.

**GET /v1/orders/{uuid}**

- Recupera o detalhe de um pedido (`/orders/{uuid}/detail` no CRM) e agrega `local_map` se existir mapeamento em MySQL.

**PUT /v1/orders/{uuid}/status**

- Atualiza status/notas no CRM.
- Payload: `{ "status": "payment_confirmed", "note": "opcional" }` (strings validadas entre 1-255 caracteres).
- Persiste histórico local na tabela `orders_map` para auditoria (`synced_at`, `data`).

#### Relatorios dinamicos (/v1/reports)

**GET /v1/reports**

- Lista os relatórios configurados em `config/reports.php` com suas colunas e parâmetros esperados (`params`).

**GET /v1/reports/{key}**

- Executa o relatório definido pela chave (closures ou classes em `app/Application/Reports`).
- Query params comuns: `page`, `per_page` (até 500), `sort`, `dir`, `_cache_enabled=false` para ignorar cache, `_cache_ttl=60` para sobrescrever TTL, `_fetch_all=true` para coletar todas as páginas do CRM quando suportado.
- Retorna envelope com `data`, `summary`, `meta` (inclui `cache_hit`, `took_ms`, `source`), `columns` e `links.export_{csv,json}`.
- Inclui header `X-Cache: HIT|MISS; key=<hash>` quando o cache Redis está habilitado.

**POST /v1/reports/{key}/export**

- Reaproveita os mesmos filtros do GET e gera arquivo.
- Query `format=csv|json` (default `csv`).
- Resposta `200` com `Content-Type` e `Content-Disposition` apropriados.

| Chave | Título | Principais filtros | Observações |
|-------|--------|--------------------|-------------|
| `orders.missing_schedule` | Pedidos confirmados sem agendamento | `product[slug]`, `order[created-start]`, `order[created-end]` | Janela padrão 90 dias, inclui `uuid`, `customer_name`, `customer_whatsapp`, `product`, `created_at`.
| `phones.for_campaign` | Telefones elegíveis para campanhas | `product[slug]`, `order[status]`, `format=plain` | Normaliza WhatsApp e remove duplicados; `summary.unique_numbers` exibe o total.
| `orders.without_participants` | Pedidos sem participantes confirmados | `product[slug]`, `order[created-start]`, `order[created-end]` | Usa includes `participants,items,customer`; `summary.total` sinaliza itens retornados.
| `orders.finalized_late_selections` | Seleções finalizadas há 10+ dias sem fechamento | `order[selection-start]`, `order[selection-end]`, `order[status]` | Calcula `days_since_selection` e média em `summary.avg_days_since_selection`.
| `orders.not_closed` | Sessões confirmadas não fechadas | `order[session-start]`, `order[session-end]`, `order[status]` | Janela padrão últimos 30 dias; destaca `session_date`.
| `participants.under_8` | Participantes com menos de 8 anos | `product[slug]`, `order[created-start]`, `order[created-end]` | Consolida idade a partir de `birthdate`; `summary.percent_under_8` resume o cenário.
| `orders.presale_vs_current` | Comparativo pré-venda vs ano atual | `current_year`, `previous_year`, `current_start`, `current_end`, `previous_start`, `previous_end`, `product[slug]` | Varre até 10 páginas por período; resumo inclui receitas e contagem de pedidos por pacote.

> Adicione novos relatórios editando `config/reports.php` (closures) ou criando classes que estendam `BaseReport` em `app/Application/Reports`.

#### Campanhas (/v1/campaigns)

**GET /v1/campaigns/schedule**

- Consulta agenda de disparos no CRM (`campaigns/schedule/search`).
- Filtros: `campaign_id` -> `campaign[id]`, `contact_phone` -> `contacts[phone]`. Parâmetros adicionais podem ser encaminhados na notação nativa do CRM (`campaign[status]`, `schedule[start_at]`, `schedule[finish_at]`, `contacts[segment]`, `q`).
- Suporta `page`/`per_page`, `fetch=all`, `sort=campaign_id:asc` (formato `<campo>:<direção>`), e devolve `links` conforme CRM.

**POST /v1/campaigns/schedule/execute**

- Agenda uma nova campanha.
- Campos obrigatórios: `campaign` (inteiro > 0), `start_at` (ISO 8601, convertido para UTC) e `contacts`.
- Contatos aceitos como string (linhas `dddnumero;Nome`), array de strings ou array de objetos (monta CSV com cabeçalhos `phone;name;coupon`).
- Campos opcionais: `finish_at` (>= `start_at`), `instance`, `use_leads_system`, `order`, `customer`, `product.reference`, `product.slug`; chave legada `product.referecen` é normalizada.
- Números inteiros e datas são saneados pelo `CampaignSchedulePayloadNormalizer`, respeitando o fuso de `APP_TIMEZONE`.

**POST /v1/campaigns/schedule/{id}/abort**

- Aborta uma campanha agendada.
- Requer apenas o `id` do agendamento no path; corpo vazio.
- Retorna envelope de sucesso com os dados fornecidos pelo CRM e cabeçalho `X-Request-Id`.

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

## WhatsApp (Z-API)

Integração com a Z-API para envio de mensagens e mídias no WhatsApp. Todos os endpoints seguem o envelope padrão de sucesso/erro e exigem o header `X-API-Key`. Em caso de falha da Z-API, retornamos 502 (bad_gateway) com detalhes adicionais quando `APP_DEBUG=true`.

### /v1/whatsapp/text

| Método | Descrição | Auth | Body obrigatório |
|--------|-----------|------|------------------|
| POST   | Envia mensagem de texto para um número. | Sim  | `{ "phone": "DDD...", "message": "..." }` |

Regras principais:
- `phone`: somente dígitos, mínimo 10 e máximo 15 caracteres.
- `message`: texto entre 1 e 4096 caracteres.

**Exemplo**
```bash
curl -X POST "http://localhost:8080/v1/whatsapp/text"   -H "X-API-Key: $APP_API_KEY" -H "Content-Type: application/json"   -d '{"phone":"5548999999999","message":"Olá, mundo!"}'
```

### /v1/whatsapp/audio

| Método | Descrição | Auth | Body obrigatório |
|--------|-----------|------|------------------|
| POST   | Envia áudio (link http(s) ou data URI `data:audio/...`) para um contato. | Sim | `{ "phone": "...", "audio": "https://..." }` |

Campos opcionais: `delayMessage` (1–15), `delayTyping` (1–15), `viewOnce`, `async`, `waveform`.

```bash
curl -X POST "http://localhost:8080/v1/whatsapp/audio"   -H "X-API-Key: $APP_API_KEY" -H "Content-Type: application/json"   -d '{"phone":"5511999999999","audio":"https://dominio.com/exemplo.mp3","viewOnce":false}'
```

### /v1/whatsapp/image

| Método | Descrição | Auth | Body obrigatório |
|--------|-----------|------|------------------|
| POST   | Envia imagem para o contato (URL http(s) ou `data:image/...`). | Sim | `{ "phone": "...", "image": "https://..." }` |

Opcionais: `caption` (≤3000), `messageId`, `delayMessage`, `viewOnce`.

```bash
curl -X POST "http://localhost:8080/v1/whatsapp/image"   -H "X-API-Key: $APP_API_KEY" -H "Content-Type: application/json"   -d '{"phone":"5511999999999","image":"https://www.z-api.io/wp-content/themes/z-api/dist/images/logo.svg","caption":"Logo"}'
```

### /v1/whatsapp/document

| Método | Descrição | Auth | Body obrigatório |
|--------|-----------|------|------------------|
| POST   | Envia documentos utilizando `send-document/{extension}` da Z-API. | Sim | `{ "phone": "...", "document": "https://...", "extension": "pdf" }` |

Opcionais: `fileName`, `caption`, `messageId`, `delayMessage`, `editDocumentMessageId`.

```bash
curl -X POST "http://localhost:8080/v1/whatsapp/document"   -H "X-API-Key: $APP_API_KEY" -H "Content-Type: application/json"   -d '{"phone":"5544999999999","document":"https://expoforest.com.br/wp-content/uploads/2017/05/exemplo.pdf","extension":"pdf","fileName":"Meu PDF"}'
```

### /v1/whatsapp/status/image

| Método | Descrição | Auth | Body obrigatório |
|--------|-----------|------|------------------|
| POST   | Publica imagem no status (expira em 24h). | Sim | `{ "image": "https://..." }` |

`caption` é opcional (≤3000). Aceita data URI.

### /v1/whatsapp/status/video

| Método | Descrição | Auth | Body obrigatório |
|--------|-----------|------|------------------|
| POST   | Publica vídeo no status (limite máximo 10 MB). | Sim | `{ "video": "https://..." }` |

`caption` opcional. Valide o tamanho do arquivo quando possível antes de enviar.

> **Dica:** configure o webhook da Z-API para acompanhar respostas assíncronas quando usar `async=true` em `/v1/whatsapp/audio`.


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
