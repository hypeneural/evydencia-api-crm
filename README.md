# Evy API

API REST construída em Slim 4 para integrar serviços internos com o CRM Evydencia. O projeto oferece endpoints para consulta e atualização de pedidos, relatórios de itens vendidos e programação de campanhas, aplicando um envelope de resposta consistente, autenticação via API Key, rate limit e observabilidade.

## Sumário

- [Tecnologias](#tecnologias)
- [Arquitetura](#arquitetura)
- [Setup](#setup)
  - [Requisitos](#requisitos)
  - [Instalação](#instalação)
  - [Configuração](#configuração)
  - [Execução](#execução)
- [Estrutura de Pastas](#estrutura-de-pastas)
- [Padrões Globais](#padrões-globais)
  - [Autenticação](#autenticação)
  - [Envelope de Resposta](#envelope-de-resposta)
  - [Erros (RFC 7807)](#erros-rfc-7807)
  - [Parâmetros de Lista](#parâmetros-de-lista)
- [Integração com o CRM Evydencia](#integração-com-o-crm-evydencia)
- [Endpoints](#endpoints)
  - [/health](#health)
  - [/v1/orders/search](#v1orderssearch)
  - [/v1/orders/{uuid}](#v1ordersuuid)
  - [/v1/orders/{uuid}/status](#v1ordersuuidstatus)
  - [/v1/reports/sold-items](#v1reportssold-items)
  - [/v1/campaigns/schedule](#v1campaignsschedule)
- [Logs e Observabilidade](#logs-e-observabilidade)
- [Rate Limiting](#rate-limiting)
- [Roadmap / Próximos Passos](#roadmap--próximos-passos)
- [Licença](#licença)

## Tecnologias

- **PHP 8.3**
- **Slim Framework 4** para roteamento e middlewares
- **PHP-DI** como container de dependências
- **Guzzle 7** para comunicação HTTP com o CRM Evydencia
- **Predis** para cache/controle de rate limit em Redis
- **Monolog 2** para logging estruturado
- **vlucas/phpdotenv** para gerenciamento de variáveis de ambiente
- **Respect/Validation** para validação de payloads

## Arquitetura

A solução segue um design modular e orientado a domínio:

- `app/Actions`: controladores HTTP enxutos, focados em orquestração e respostas.
- `app/Application`: serviços de aplicação, DTOs, mapeadores da DSL pública para o CRM.
- `app/Domain`: contratos e exceções específicas.
- `app/Infrastructure`: integrações (HTTP, logging, cache, persistência, migrations).
- `app/Middleware`: segurança, rate limit e observabilidade.
- `config/`: bootstrapping (settings, dependências, middlewares, rotas).

## Setup

### Requisitos

- PHP 8.3+
- Composer 2.8+
- Redis (para rate limit) — opcional, mas recomendado
- MySQL 8.4+ (para persistência opcional de `orders_map`)

### Instalação

```bash
composer install
```

### Configuração

1. Copie o arquivo `.env.example` para `.env`.
2. Ajuste as variáveis principais:
   - `APP_API_KEY`: chave privada exigida pelo header `X-API-Key`.
   - `CRM_BASE_URL`: normalmente `https://evydencia.com/api`.
   - `CRM_TOKEN`: token fornecido pelo Evydencia (use valor direto, sem `Bearer`).
   - Variáveis de Redis (`REDIS_*`) se usar rate limiting distribuído.
   - Parâmetros de banco (`DB_*`) caso use a persistência local opcional.

### Execução

#### Servidor embutido

```bash
composer start
```

O servidor roda por padrão em `http://localhost:8080`.

#### Apache/Laragon

- Aponte o DocumentRoot para `public/`.
- Certifique-se de que o rewrite esteja habilitado para o `.htaccess` existente.

## Estrutura de Pastas

```
├── app/
│   ├── Actions/              # HTTP actions organizadas por contexto (Orders, Reports, Campaigns)
│   ├── Application/          # Serviços, DTOs, mapeadores e suportes auxiliares
│   ├── Domain/               # Exceções e contratos de domínio
│   ├── Infrastructure/       # Integrações (HTTP, logging, cache, persistence)
│   ├── Middleware/           # Middlewares de segurança, rate limit, logging
│   └── Settings/             # Aggregator de configurações
├── config/                   # settings.php, dependencies.php, middleware.php, routes.php
├── public/                   # front controller (index.php) e .htaccess
├── database/                 # espaço para assets SQL
├── var/logs/                 # diretório de logs (ignorado no git)
├── tests/                    # pasta reservada para testes
├── vendor/                   # dependências do Composer
├── .env.example              # modelo de variáveis de ambiente
└── composer.json             # manifesto do projeto
```

## Padrões Globais

### Autenticação

- Header obrigatório: `X-API-Key`.
- Valor definido em `APP_API_KEY` no `.env`.
- Endpoints `/v1/*` retornam `401 Unauthorized` com payload RFC 7807 se a chave estiver ausente/inválida.

### Envelope de Resposta

Todas as respostas seguem o mesmo formato:

```json
{
  "data": [],
  "meta": {
    "page": 1,
    "size": 50,
    "count": 0,
    "total_items": null,
    "total_pages": null,
    "elapsed_ms": 0
  },
  "links": {
    "self": "",
    "first": "",
    "prev": null,
    "next": null,
    "last": null
  },
  "trace_id": "f0e1d2c3b4a59687",
  "source": {
    "system": "crm",
    "endpoint": "orders/search"
  }
}
```

- `trace_id` é sempre um hex de 16 chars (`bin2hex(random_bytes(8))`).
- `source.endpoint` corresponde ao endpoint do CRM chamado.
- Respostas de recurso único (ex.: `/v1/orders/{uuid}`) podem omitir `meta/links`.

### Erros (RFC 7807)

Erros sempre retornam `Content-Type: application/problem+json` e seguem a RFC 7807. Exemplos:

- **422 Validation**

  ```json
  {
    "type": "https://api.local/errors/validation",
    "title": "Parâmetros inválidos",
    "status": 422,
    "detail": "Alguns parâmetros estão inválidos ou ausentes.",
    "errors": [
      { "field": "page[size]", "message": "máximo 200" }
    ],
    "trace_id": "..."
  }
  ```

- **401 Unauthorized** (API Key ausente/errada)

  ```json
  {
    "type": "https://api.local/errors/unauthorized",
    "title": "Unauthorized",
    "status": 401,
    "detail": "Invalid API key.",
    "trace_id": "..."
  }
  ```

- **429 Too Many Requests** (rate limit excedido)

  Inclui header `Retry-After`.

- **502 Bad Gateway** (timeout/erro do CRM)

  ```json
  {
    "type": "about:blank",
    "title": "Bad Gateway",
    "status": 502,
    "detail": "CRM timeout",
    "trace_id": "..."
  }
  ```

### Parâmetros de Lista

Todos os endpoints de lista aceitam a DSL pública abaixo:

- **Paginação**
  - `page[number]` (default 1)
  - `page[size]` (default 50, máx. 200)
- **Ordenação**
  - `sort=campo,-outro` (`-` indica ordem desc)
- **Filtros**
  - Igualdade: `filter[status]=payment_confirmed`
  - Intervalo: `filter[created_at][gte]=YYYY-MM-DD`, `filter[created_at][lte]=YYYY-MM-DD`
  - Like: `filter[customer_name][like]=ana`
  - Lista: `filter[status][in]=a,b,c`
- **Projeção**
  - `fields[orders]=uuid,status,customer.name`
- **Agregação**
  - `all=true` percorre todas as páginas do CRM via `links.next` e consolida o resultado (use com cautela).

## Integração com o CRM Evydencia

- Base URL (default): `https://evydencia.com/api`
- Header obrigatório nas requisições ao CRM:
  - `Accept: application/json`
  - `Authorization: {CRM_TOKEN}` (sem `Bearer`)
- Cliente Guzzle configurado com `timeout=30` e `http_errors=false`.
- Endpoints consumidos:
  - `GET /orders/search`
  - `GET /orders/{uuid}/detail`
  - `PUT /order/status`
  - `GET /reports/sold-items`
  - `GET /campaigns/schedule/search`
- Timeout ou falha de rede → 502 para o cliente, logando sem expor o token.

## Endpoints

### /health

- **Método:** `GET`
- **Descrição:** Checagem simples de saúde.
- **Headers:** `X-API-Key` opcional (não exigido).
- **Resposta:** Envelope com `status: ok` e timestamp atual.

### /v1/orders/search

- **Método:** `GET`
- **Descrição:** Busca pedidos no CRM com os filtros públicos.
- **Headers:** `X-API-Key`
- **Query params:** DSL pública (paginadores, filtros, sort, fields, all).
- **Mapeamento principal:**
  - `filter[uuid] → order[uuid]`
  - `filter[status] → order[status]`
  - `filter[created_at][gte] → order[created-start]`
  - `filter[customer_name][like] → customer[name]=%valor%`
  - `filter[status][in] → order[status]=a,b,c`
  - `page[number] → page`
  - `page[size] → per_page`
- **Resposta:** Envelope padrão com `meta.elapsed_ms` e links calculados. Se `all=true`, todo o conjunto é retornado com `total_pages=1`.
- **Exemplo:**

  ```bash
  curl "http://localhost:8080/v1/orders/search?filter[status]=payment_confirmed&filter[created_at][gte]=2025-09-01&sort=-created_at&page[number]=1&page[size]=50" \
    -H "X-API-Key: SUA_CHAVE"
  ```

### /v1/orders/{uuid}

- **Método:** `GET`
- **Descrição:** Detalhe de um pedido específico.
- **Headers:** `X-API-Key`
- **Resposta:**
  - `data` com o payload do CRM (merge com `local_map` se existir registro local).
  - `source.endpoint = orders/{uuid}/detail`.

### /v1/orders/{uuid}/status

- **Método:** `PUT`
- **Headers:** `X-API-Key`, `Content-Type: application/json`
- **Body:**

  ```json
  {
    "status": "waiting_product_retrieve",
    "note": "opcional"
  }
  ```

- **Validações:** `uuid` obrigatório, `status` string 2-64 chars, `note` opcional.
- **Processo:** Encaminha `PUT /order/status` com `{"uuid":"...","status":"...","note":"..."}`; persiste em `orders_map` quando configurado.
- **Resposta:** Payload do CRM no envelope de recurso único.

### /v1/reports/sold-items

- **Método:** `GET`
- **Descrição:** Consulta relatório de itens vendidos.
- **Headers:** `X-API-Key`
- **Query params:**
  - `filter[item_name] → item[name]`
  - `filter[item_slug] → item[slug]`
  - `filter[item_ref] → item[ref]`
  - `filter[created_at][gte|lte] → order[created-start|end]`
  - `page[number|size]`, `sort`, `all`
- **Resposta:** Envelope de lista conforme padrão.

### /v1/campaigns/schedule

- **Método:** `GET`
- **Descrição:** Lista programação de campanhas através do CRM.
- **Headers:** `X-API-Key`
- **Query params:**
  - `filter[campaign_id] → campaign[id]`
  - `filter[contact_phone] → contacts[phone]`
  - `page[number|size]`, `sort`, `all`
- **Resposta:** Envelope de lista com metadados e links.

## Logs e Observabilidade

- Middleware `RequestLoggingMiddleware` registra cada requisição (método, rota, status, tempo em ms, `trace_id`).
- `Trace-Id` é retornado ao cliente para correlação.
- Erros críticos são logados com contexto minimalista (sem PII ou tokens).
- Caminho do log configurável via `LOG_PATH` (padrão: `var/logs/app.log`).

## Rate Limiting

- Middleware `RateLimitMiddleware` usa Redis (Predis) para impor limite por IP/rota.
- Configuração via `.env` (`RATE_LIMIT_PER_MINUTE`, `REDIS_*`).
- Sem Redis habilitado (`REDIS_HOST` vazio), o rate limiting é automaticamente ignorado.

## Roadmap / Próximos Passos

- Adicionar testes automatizados (PHPUnit/Pest) para QueryMapper, serviços e middlewares.
- Implementar caching de algumas consultas mediante parâmetros (`ETag`/`Last-Modified`).
- Suporte a paginação “offset/limit” caso o CRM exponha.
- CLI de migração para manter `orders_map` sincronizada.
- Docker Compose para desenvolvimento homogêneo (PHP + Redis + MySQL).

## Licença

Projeto proprietário. Ajuste conforme necessidade antes de publicação.
