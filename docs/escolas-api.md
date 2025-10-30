# Escola Connect - Guia de Endpoints de Escolas

Este documento centraliza o contrato dos endpoints expostos pelo modulo de escolas. Para a referencia completa em OpenAPI consulte `docs/openapi/schools.yaml`.

## Autenticacao & Convencoes
- Todas as rotas exigem `X-API-Key` valido.
- Respostas seguem envelope padrao:
```json
{
  "success": true,
  "data": [],
  "meta": {
    "page": 1,
    "per_page": 20,
    "total": 173,
    "source": "database",
    "filters": {}
  },
  "links": {
    "self": "/v1/escolas",
    "next": null,
    "prev": null
  },
  "trace_id": "013fa3d16e625f5e"
}
```
- Erros retornam `success=false` com objeto `error { code, message, ... }`.
- Paginacao usa `page` + `per_page` (default 20). `fetch=all` ignora limites (apenas para usuarios internos).

## Sumario de Endpoints
| Verbo | Rota | Descricao |
|-------|------|-----------|
| GET | `/v1/cidades` | Agregados por cidade (totais, etapas, turnos). |
| GET | `/v1/cidades/{id}/bairros` | Agregados do municipio e bairros; `withEscolas=true` embute escolas. |
| GET | `/v1/escolas` | Listagem paginada com filtros, busca e ordenacao. |
| GET | `/v1/escolas/{id}` | Detalhe completo (etapas, indicadores, observacoes atuais). |
| PATCH | `/v1/escolas/{id}` | Atualiza campos editaveis (panfletagem, obs, indicadores, periodos). Requer `If-Match`. |
| GET | `/v1/escolas/{id}/panfletagem/logs` | Historico de alteracoes de panfletagem (paginacao). |
| GET | `/v1/escolas/{id}/observacoes` | Historico de observacoes. |
| POST | `/v1/escolas/{id}/observacoes` | Cria nova observacao. |
| PUT | `/v1/escolas/{id}/observacoes/{obsId}` | Edita observacao existente. |
| DELETE | `/v1/escolas/{id}/observacoes/{obsId}` | Remove observacao. |
| GET | `/v1/filtros/cidades` | Fonte de dados para filtros de cidade (totais, pendentes). |
| GET | `/v1/filtros/bairros` | Fonte de dados para filtros de bairro. |
| GET | `/v1/filtros/periodos` | Lista periodos disponiveis (Matutino/Vespertino/Noturno). |
| GET | `/v1/kpis/overview` | KPIs resumidos de panfletagem e etapas. |
| GET | `/v1/kpis/historico` | Serie temporal (por data) com feitas/pendentes. |
| POST | `/v1/sync/mutations` | Enfileira mutacoes offline (aceita `tipo` ou `type`, `payload` ou `updates`). |
| GET | `/v1/sync/changes` | Retorna delta desde um marcador (`since` ISO8601 ou `sinceVersion`). |

## Destaques de Implementacao
- **Agregadores**: `includeBairros=true` ou `withEscolas=true` habilitam carregamento expandido com ETag. Responses contem `meta.etag`.
- **Filtros avancados** (lista de escolas): suportam `filter[cidade_id][]`, `filter[bairro_id][]`, `filter[tipo][]`, `filter[status]` (`pendente|feito|todos`), `filter[periodos][]`, `search` (nome/diretor/endereco) e `sort` (ex.: `sort=panfletagem,-total_alunos`).
- **Concorrencia otimista**: PATCH requer `If-Match: <versao_row>` e parametro `versao_row` no corpo. 409 e retornado quando a versao diverge, incluindo estado atual para merge manual.
- **Logs de panfletagem**: cada item traz `status_anterior`, `status_novo`, `observacao`, `usuario { id, nome }` e `criado_em` UTC.
- **Sync offline**:
  - `POST /v1/sync/mutations` aceita arrays com `client_id`, `tipo` (`updateEscola`, etc.), `payload` (ou `updates`) e `versao_row`. O servico retorna `mutations[] { id, client_id, tipo, status, client_mutation_id?, escola_id? }`.
  - `GET /v1/sync/changes` entrega blocos de `cidades`, `bairros`, `escolas` ja enriquecidos com periodos e etapas, alem de `next_since` para paginacao incremental.

## Exemplos Rapidos

### Atualizacao de Escola
```http
PATCH /v1/escolas/1
If-Match: 5
Content-Type: application/json

{
  "versao_row": 5,
  "panfletagem": true,
  "obs": "Entrega concluida",
  "periodos": ["Matutino", "Vespertino"],
  "indicadores": { "tem_pre": true }
}
```
- **Respostas**: `200` com escola atualizada (`versao_row` incrementado), `409` conflito quando `versao_row` diverge, `422` para payload invalido.

### Lote de Mutations
```json
POST /v1/sync/mutations
{
  "client_id": "device-automated-test",
  "mutations": [
    {
      "client_mutation_id": "mut-123",
      "type": "updateEscola",
      "escola_id": 1,
      "updates": { "panfletagem": false, "versao_row": 7 }
    }
  ]
}
```
Resposta:
```json
{
  "success": true,
  "data": {
    "mutations": [
      {
        "id": 42,
        "client_id": "device-automated-test",
        "tipo": "updateEscola",
        "status": "pending",
        "client_mutation_id": "mut-123",
        "escola_id": 1
      }
    ]
  },
  "meta": { "count": 1, "source": "database" },
  "links": { "self": "/v1/sync/mutations", "next": null, "prev": null },
  "trace_id": "82db5249bb04efcd"
}
```

### Delta de Mudancas
```
GET /v1/sync/changes?sinceVersion=0&limit=100
```
```json
{
  "success": true,
  "data": {
    "cidades": [...],
    "bairros": [...],
    "escolas": [
      {
        "id": 1,
        "cidade_id": 1,
        "nome": "CEI Prof. Marco Aurelio",
        "panfletagem": true,
        "versao_row": 7,
        "periodos": ["Matutino", "Vespertino"],
        "etapas": { "bercario_1a2": 10, "...": 0 },
        "atualizado_em": "2025-10-30T03:43:24Z"
      }
    ],
    "next_since": "2025-10-30T06:43:24Z"
  },
  "meta": {
    "page": 1,
    "source": "database",
    "filters": { "since": "", "limit": 100 }
  },
  "links": { "self": "/v1/sync/changes?sinceVersion=0&limit=100", "next": null, "prev": null },
  "trace_id": "e7ee16389d1fc901"
}
```

## Referencia Cruzada
- **OpenAPI**: `docs/openapi/schools.yaml` (OpenAPI 3.0.3).
- **Seeds**: `database/seeds/*` contem fixtures de cidades/bairros/escolas.
- **Testes de regressao**: `test_endpoints.php` executa fluxo completo (agregadores, CRUD, observacoes, sync).

---
Manter este guia alinhado a cada alteracao de rota. Ao adicionar campos ou endpoints:
1. Atualize o codigo e os DTOs correspondentes.
2. Ajuste `docs/openapi/schools.yaml`.
3. Documente aqui o comportamento esperado (payloads, cabecalhos, codigos de status).
