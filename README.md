# Evy API

API REST em Slim 4 com PHP-DI que concentra as integracoes com o CRM Evydencia, recursos locais em MySQL e operacoes de WhatsApp via Z-API. Todas as respostas seguem um envelope consistente, propagam `trace_id` e aplicam autenticao por API Key no namespace `/v1`.

## Sumario
- [Visao geral](#visao-geral)
- [Requisitos](#requisitos)
- [Configuracao](#configuracao)
- [Execucao local](#execucao-local)
- [Padroes de resposta e headers](#padroes-de-resposta-e-headers)
- [Arquitetura do projeto](#arquitetura-do-projeto)
- [Fluxo CRM Evydencia](#fluxo-crm-evydencia)
- [Banco de dados local](#banco-de-dados-local)
- [Endpoints HTTP](#endpoints-http)
  - [Rotas publicas](#rotas-publicas)
  - [Autenticacao e rate limit](#autenticacao-e-rate-limit)
  - [Blacklist (recurso local)](#blacklist-recurso-local)
  - [Agendamentos de postagens](#agendamentos-de-postagens)
  - [Pedidos (CRM)](#pedidos-crm)
  - [Relatorios (CRM)](#relatorios-crm)
  - [Campanhas (CRM)](#campanhas-crm)
  - [WhatsApp (Z-API)](#whatsapp-z-api)
    - [Envio de mensagens](#envio-de-mensagens)
    - [Status e timeline](#status-e-timeline)
    - [Contatos](#contatos)
    - [Chats e etiquetas](#chats-e-etiquetas)
    - [Perfil](#perfil)
- [Observabilidade](#observabilidade)
- [Ferramentas de suporte](#ferramentas-de-suporte)
- [Proximos passos sugeridos](#proximos-passos-sugeridos)

## Visao geral

- Envelope padrao (`success`, `data`, `meta`, `links`, `trace_id`) e erros estruturados.
- Cliente tipado para o CRM Evydencia com timeout, retries e mapeamento de erros.
- Query DSL unica para filtros, ordenacao, projecao de campos e paginacao.
- Recursos locais persistidos em MySQL (blacklist de WhatsApp e scheduled posts) prontos para cache ETag/Redis.
- Suite completa de integracoes Z-API: mensagens, contatos, status, tags e utilitarios de chat.
- Observabilidade com logs estruturados, propagacao de trace e cabecalhos de rate limit.

## Requisitos

- PHP 8.3+
- Composer 2.8+
- MySQL 8.x
- Redis 6.x (opcional, usado para rate limit e cache de agendamentos)

## Configuracao

1. Copie `.env.example` para `.env`.
2. Ajuste as variaveis obrigatorias:
   - `APP_ENV`, `APP_DEBUG`, `APP_API_KEY`.
   - `CRM_BASE_URL` e `CRM_TOKEN` (token puro).
   - Credenciais `DB_*` se utilizar os recursos locais.
3. Opcional:
     - `REDIS_*` habilita cache e rate limiting.
     - `ZAPI_*` aponta para a instancia Z-API (base url, instance, token e timeout).
     - `RATE_LIMIT_PER_MINUTE` redefine o limite padrao (60 req/min por IP+rota).
     - `REPORT_PHOTOS_READY_STATUS_SLUGS` restringe os status considerados no relatório `orders.photos_ready` (ex.: `photos_ready,photos_delivered`).
     - Ajuste `LOG_*` para mudar path ou nivel do Monolog.

## Execucao local

```bash
# Instalar dependencias
composer install

# Servidor PHP embutido (http://localhost:8080)
php -S 127.0.0.1:8080 -t public

# Ou utilize o alias registrado no composer.json
composer start
```

Com Laragon/Apache aponte o DocumentRoot para `public/`. Em ambientes com Nginx lembre-se de encaminhar `Authorization`, `X-API-Key` e `Trace-Id`.

## Padroes de resposta e headers

- Todas as respostas trazem `trace_id` (envelope e cabecalho `X-Request-Id`).
- Strutura de sucesso:
  ```json
  {
    "success": true,
    "data": {...},
    "meta": {...},
    "links": {...},
    "trace_id": "abcd1234"
  }
  ```
- Erros geram `success: false` com `error`, `message`, `status_code` e lista de `violations`.
- Requisicoes autenticadas exigem `X-API-Key: <APP_API_KEY>`.
- Rate limit exposto via `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.
- `Idempotency-Key` em `POST /v1/blacklist` evita duplicidade por 24h.
- Listagens enviam `ETag` quando ha cache; use `If-None-Match` para economizar trafego.

## Arquitetura do projeto

```
app/
  Actions/                # Controladores HTTP (CRM, recursos locais, WhatsApp, docs, health)
  Application/
    DTO/                  # Objetos de transferencia e filtros tipados
    Services/             # Orquestracao (OrderService, ReportService, ScheduledPostService, WhatsAppService)
    Support/              # ApiResponder, QueryMapper, validadores
  Domain/
    Exception/            # Excecoes de dominio e integracao (CRM, Z-API, conflito)
    Repositories/         # Contratos para persistencia MySQL
  Infrastructure/
    Cache/                # Adaptadores Redis (rate limit, cache de agendamentos)
    Http/                 # Clientes externos (Evydencia, Z-API) e middlewares Guzzle
    Logging/              # LoggerFactory + configuracao Monolog
    Persistence/          # Repositorios PDO e migrations SQL
  Middleware/             # Autenticacao, rate limit, trace, logging
  OpenApi/                # Descricoes OpenAPI (schemas e builders)
  Settings/               # Holder de configuracoes carregadas do env
config/
  dependencies.php        # Registrations PHP-DI
  middleware.php          # Stack global (trace, error handler, cors)
  routes.php              # Agrupamento das rotas /doc, /health e namespace /v1
public/
  index.php               # Front controller
cli_request.php           # Helper para testar rotas via CLI
composer.json             # Scripts utilitarios (openapi:build, start, lint)
database/                 # Scripts SQL e seeds (quando aplicavel)
docs/                     # Documentacao adicional (roadmap, TODO)
tmp/, var/                # Arquivos gerados em runtime (logs, cache, openapi)
```

## Fluxo CRM Evydencia

- `OrderService` aplica QueryMapper para traduzir filtros REST para o DSL do CRM (`order[status]`, `customer[email]`, `product[uuid]`, etc.).
- `ReportService` encapsula o motor de relatorios dinamicos (`/v1/reports`), com validacao por chave, exportacao CSV/JSON e opcao de cache.
- `CampaignService` executa agendamentos de campanhas (`/v1/campaigns/*`), sincronizando timezone e validando janelas com base na API do CRM.
- Erros do CRM geram `bad_gateway` com status propagado; indisponibilidade retorna 502 com mensagem amigavel.

## Banco de dados local

O schema recomendado (ajuste o nome conforme `DB_DATABASE`):

```sql
CREATE TABLE IF NOT EXISTS whatsapp_blacklist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    whatsapp VARCHAR(20) NOT NULL UNIQUE,
    has_closed_order TINYINT(1) DEFAULT 0,
    observation TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scheduled_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('text','image','video') NOT NULL,
    message TEXT NULL,
    image_url VARCHAR(255) NULL,
    video_url VARCHAR(255) NULL,
    caption VARCHAR(255) NULL,
    scheduled_datetime DATETIME NOT NULL,
    zaapId VARCHAR(50) NULL,
    messageId VARCHAR(50) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_scheduled_datetime (scheduled_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Execute o script em `database/migrations` ou utilize um client MySQL de sua preferencia.

## Endpoints HTTP

### Rotas publicas

| Metodo | Rota   | Descricao |
|--------|--------|-----------|
| GET    | /doc   | Swagger UI + OpenAPI JSON gerado por `composer openapi:build`. |
| GET    | /health| Status basico (app version, timestamp, checks de conexao). |

### Autenticacao e rate limit

- Todas as rotas `/v1/**` exigem `X-API-Key`.
- Middleware de rate limit utiliza Redis (fallback em memoria). Ajuste `RATE_LIMIT_PER_MINUTE` no `.env`.
- O middleware de trace injeta `trace_id` e logs estruturados em `var/logs/app.log`.

### Blacklist (recurso local)

| Metodo | Rota | Descricao | Notas |
|--------|------|-----------|-------|
| GET    | /v1/blacklist | Lista paginada com filtros (`name`, `whatsapp`, `has_closed_order`). | Suporta `sort`, `fields[blacklist]` e ETag. |
| POST   | /v1/blacklist | Cria entrada com `name`, `whatsapp`, `has_closed_order` opcional, `observation`. | Aceita `Idempotency-Key`; retorna 201 ou 200 (hit idempotente). |
| GET    | /v1/blacklist/{id} | Detalhe por ID. | 404 quando nao encontrado. |
| PUT/PATCH | /v1/blacklist/{id} | Atualiza campos parciais. | Sanitiza whatsapp para apenas digitos. |
| DELETE | /v1/blacklist/{id} | Remove registro. | Retorna 204 em sucesso. |

### Agendamentos de postagens

| Metodo | Rota | Descricao | Campos principais |
|--------|------|-----------|-------------------|
| GET    | /v1/scheduled-posts | Lista com filtros (`type`, `scheduled_datetime`, busca textual). | Suporta `fetch=all`, `sort`, `fields[scheduled_posts]`, cache ETag. |
| POST   | /v1/scheduled-posts | Cria agendamento. | `type` (text/image/video), `scheduled_datetime` (America/Sao_Paulo), campos dependentes (`message`, `image_url`, `video_url`). |
| GET    | /v1/scheduled-posts/{id} | Detalhe de um agendamento. | 404 se inexistente. |
| PUT/PATCH | /v1/scheduled-posts/{id} | Atualiza tipo, conteudo e data. | Valida consistencia entre tipo e campos obrigatorios. |
| POST   | /v1/scheduled-posts/{id}/mark-sent | Marca como enviado e persiste `messageId`/`zaapId`. | Atualiza timestamps e invalida cache. |
| DELETE | /v1/scheduled-posts/{id} | Remove agendamento. | Retorna 204. |
| GET    | /v1/scheduled-posts/ready | Lista itens prontos para envio. | Query `limit` (default 50) + cabecalho `X-Total-Count`. |

### Pedidos (CRM)

| Metodo | Rota | Descricao | Filtros |
|--------|------|-----------|---------|
| GET    | /v1/orders/search | Busca pedidos no CRM com enriquecimento local. | `page`, `per_page`, `fetch=all`, `sort`, `fields[orders]`, `q`, `order[status]`, `order[created-start|end]`, `customer[email|whatsapp]`, `product[uuid]`. |
| GET    | /v1/orders/{uuid} | Retorna pedido individual. | 404 se nao localizado no CRM. |
| PUT    | /v1/orders/{uuid}/status | Atualiza status no CRM. | Body `status`, opcional `reason`, `comment`. |

### Relatorios (CRM)

| Metodo | Rota | Descricao | Notas |
|--------|------|-----------|-------|
| GET    | /v1/reports | Lista relatorios disponiveis e metadados. | Ideal para catalogo no front. |
| GET    | /v1/reports/{key} | Executa relatorio dinamico. | Suporta filtros conforme configuracao do relatorio. |
| POST   | /v1/reports/{key}/export | Exporta resultado (CSV ou JSON). | Body `format` (`csv`/`json`), filtros opcionais. |

### Campanhas (CRM)

| Metodo | Rota | Descricao | Campos |
|--------|------|-----------|--------|
| GET    | /v1/campaigns/schedule | Consulta agendamentos atuais. | Filtros `campaign[id]`, `contacts[segment]`, intervalos de data. |
| POST   | /v1/campaigns/schedule/execute | Agenda execucao. | `campaign` (int), `start_at` (ISO 8601), `contacts` (array de phones ou segment). |
| POST   | /v1/campaigns/schedule/{id}/abort | Cancela agendamento. | `id` do agendamento vindo do CRM. |

### WhatsApp (Z-API)

#### Envio de mensagens

| Metodo | Rota | Body minimo | Observacoes |
|--------|------|-------------|-------------|
| POST | /v1/whatsapp/text | `{ "phone": "55...", "message": "..." }` | Texto simples (1-4096 chars). |
| POST | /v1/whatsapp/audio | `{ "phone": "55...", "audio": "https://..." }` | URL http(s) ou data URI `data:audio/...`; opcionais `delayMessage`, `delayTyping`, `viewOnce`, `async`, `waveform`. |
| POST | /v1/whatsapp/image | `{ "phone": "55...", "image": "https://..." }` | Opcionais `caption` (<=3000), `messageId`, `delayMessage`, `viewOnce`. |
| POST | /v1/whatsapp/document | `{ "phone": "55...", "document": "https://...", "extension": "pdf" }` | Aceita data URI; opcionais `fileName`, `caption`, `messageId`, `delayMessage`, `editDocumentMessageId`. |
| POST | /v1/whatsapp/ptv | `{ "phone": "55...", "ptv": "https://..." }` | Video pre-gravado (URL ou `data:video/...`), opcionais `messageId`, `delayMessage`. |
| POST | /v1/whatsapp/location | `{ "phone": "55...", "title": "...", "address": "...", "latitude": "-23.5", "longitude": "-46.6" }` | `delayMessage` opcional. |
| POST | /v1/whatsapp/link | `{ "phone": "55...", "message": "...", "image": "https://...", "linkUrl": "https://...", "title": "...", "linkDescription": "..." }` | Opcionais `delayMessage`, `delayTyping`, `linkType` (`SMALL|MEDIUM|LARGE`). |
| POST | /v1/whatsapp/sticker | `{ "phone": "55...", "sticker": "https://..." }` | Aceita `data:image/...`; opcionais `messageId`, `delayMessage`, `stickerAuthor`. |
| POST | /v1/whatsapp/gif | `{ "phone": "55...", "gif": "https://..." }` | GIF animado (mp4); opcionais `caption`, `messageId`, `delayMessage`. |
| POST | /v1/whatsapp/carousel | `{ "phone": "55...", "message": "...", "carousel": [...] }` | Cada card requer `text` e `image`; botoes suportam tipos `CALL`, `URL`, `REPLY`. |
| POST | /v1/whatsapp/option-list | `{ "phone": "55...", "message": "...", "optionList": { "title": "...", "buttonLabel": "...", "options": [...] } }` | Cada opcao precisa de `title`; opcional `id`, `description`; aceita `delayMessage`. |
| POST | /v1/whatsapp/call | `{ "phone": "55..." }` | Opcionais `callDuration` (1-15 segundos). |
| POST | /v1/whatsapp/message/pin | `{ "phone": "55...", "messageId": "...", "messageAction": "pin", "pinMessageDuration": "24_hours" }` | `messageAction` `pin` ou `unpin`; duracao obrigatoria quando `pin`. |

#### Status e timeline

| Metodo | Rota | Body minimo | Observacoes |
|--------|------|-------------|-------------|
| POST | /v1/whatsapp/status/text | `{ "message": "..." }` | Texto (1-4096 chars). |
| POST | /v1/whatsapp/status/image | `{ "image": "https://..." }` | Opcional `caption` (<=3000). |
| POST | /v1/whatsapp/status/video | `{ "video": "https://..." }` | Limite recomendado 10 MB; `caption` opcional. |

#### Contatos

| Metodo | Rota | Descricao | Notas |
|--------|------|-----------|-------|
| GET    | /v1/whatsapp/contacts | Lista contatos sincronizados. | Query obrigatoria `page` e `pageSize` (1-1000). |
| POST   | /v1/whatsapp/contacts/add | Adiciona contatos em lote. | Array de objetos com `firstName`, `phone`; `lastName` opcional. |
| DELETE | /v1/whatsapp/contacts/remove | Remove contatos em lote. | Array de telefones (apenas digitos). |
| GET    | /v1/whatsapp/contacts/{phone} | Metadata do contato. | `phone` no path (10-15 digitos). |

#### Chats e etiquetas

| Metodo | Rota | Descricao | Notas |
|--------|------|-----------|-------|
| PUT    | /v1/whatsapp/chats/{phone}/tags/{tag}/add | Adiciona etiqueta ao chat. | `tag` ate 255 chars. |
| PUT    | /v1/whatsapp/chats/{phone}/tags/{tag}/remove | Remove etiqueta do chat. | Validacao identica ao add. |

#### Perfil

| Metodo | Rota | Descricao |
|--------|------|-----------|
| GET    | /v1/whatsapp/profile-picture?phone=5511999999999 | Retorna link da foto de perfil mais recente. |

## Observabilidade

- Logs estruturados em `var/logs/app.log` (JSON), com `trace_id`, status, duracao e informacoes do provider.
- `trace_id` eh propagado para Guzzle (CRM e Z-API) via cabecalho `X-Trace-Id`.
- Middleware de rate limit adiciona `Retry-After` quando o limite eh excedido.
- Em modo debug (`APP_DEBUG=true`) respostas de erro Z-API e CRM incluem payload bruto em `meta.provider_body`.

## Ferramentas de suporte

- `composer openapi:build`: gera `public/openapi.json` e atualiza `public/doc` (Swagger UI).
- `composer qa`: roda formatacao e static analysis (se configurado no projeto).
- Colecoes Postman/Insomnia recomendadas: utilize o arquivo fornecido na pasta `/docs` ou gere via `openapi.json`.

## Proximos passos sugeridos

1. Automatizar testes unitarios/integrados para `QueryMapper`, `ScheduledPostService` e actions de WhatsApp.
2. Adicionar comando CLI para rodar migrations locais automaticamente.
3. Versionar colecoes Postman/Insomnia parametrizadas (`{{baseUrl}}`, `{{apiKey}}`).
4. Expandir observabilidade com metricas Prometheus (tempo de resposta, falhas no CRM/Z-API).
