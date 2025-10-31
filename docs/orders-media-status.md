# Orders Media Status API

Este guia explica como o front-end pode consumir o endpoint que cruza os pedidos do CRM da Evydencia com as pastas de midia das instancias **galeria.fotosdenatal.com** e **game.fotosdenatal.com**.

---

## Endpoint

```
GET /v1/orders/media-status
```

### Parametros de query

| Parametro | Tipo | Obrigatorio | Padrao | Descricao |
|-----------|------|-------------|--------|-----------|
| `session_start` | string (YYYY-MM-DD) | nao | `2025-09-01` | Data inicial das sessoes. Valores anteriores a 01/09/2025 sao rejeitados. |
| `session_end` | string (YYYY-MM-DD) | nao | ontem | Data final das sessoes. Deve ser menor ou igual a ontem. |
| `product_slug` | string | nao | `natal` | Slug do produto. Use `*` para remover o filtro (retorna todos os produtos). |

Se `product_slug` for omitido ou vazio, o back-end utiliza automaticamente o slug `natal`. O valor `*` desativa o filtro e replica as buscas do Postman (todos os produtos no intervalo informado). Valores sao normalizados para minusculo.

---

## Recomendacao de uso com o CRM unificado

1. **Buscar pedidos no CRM interno**
   ```
   GET /v1/orders/search?
       order[session-start]=2025-09-01&
       order[session-end]=<data_atual>&
       product[slug]=natal&
       fetch=all
   ```
   Headers necessários:
   ```
   Accept: application/json
   Authorization: <CRM_TOKEN>
   ```
   * Remova qualquer pedido cancelado (`status.id = 1` ou `status.name = "Pedido Cancelado"`).
   * Guarde `id`, `schedule_1`, `status.name`, `items[].product.name` (primeiro bundle ou item).

2. **Cruzar com midia**
   * Chame `/v1/orders/media-status` utilizando o mesmo intervalo (`session_start`, `session_end`) e, quando necessario, informe `product_slug`.
   * O back-end consulta o CRM externo da Evydencia, aplica paginação automatica (`per_page = 200`, limite de 50 paginas), ignora cancelados, e verifica se o `id` do pedido existe como pasta nos status das plataformas de galeria e game.

3. **Montar a interface**
   * Junte os dados retornados pelo CRM interno com os flags `in_gallery` e `in_game`, além dos KPIs presentes em `summary.kpis` e dos snapshots completos em `media_status`.

---

## Estrutura da resposta

```jsonc
{
  "success": true,
  "media_status": {
    "gallery": { "...": "..." },
    "game": { "...": "..." }
  },
  "data": [
    {
      "id": 4490,
      "schedule_1": "2025-10-28 17:00:00",
      "status_name": "Aguardando Retirar",
      "product_name": "Experiencia Ho-Ho-Ho",
      "in_gallery": true,
      "in_game": false
    }
  ],
  "summary": {
    "total_returned": 2,
    "skipped_canceled": 1,
    "session_window": ["2025-09-01", "2025-10-31"],
    "filters": {
      "product_slug": "natal",
      "default_product_slug": "natal"
    },
    "sources": {
      "orders": "https://evydencia.com/api/orders/search",
      "gallery_status": "https://galeria.fotosdenatal.com/status.php",
      "game_status": "https://game.fotosdenatal.com/status.php"
    },
    "kpis": {
      "total_imagens": 186,
      "media_fotos": 23.25,
      "total_galerias_ativas": 8,
      "total_jogos_ativos": 8
    },
    "orders": {
      "with_gallery": 1,
      "without_gallery": 1,
      "with_game": 0,
      "without_game": 2
    },
    "media": {
      "gallery": { "...": "..." },
      "game": { "...": "..." }
    }
  },
  "meta": {
    "elapsed_ms": 143,
    "filters": {
      "session_start": "2025-09-01",
      "session_end": "2025-10-31",
      "product_slug": "natal",
      "default_product_slug": "natal",
      "requested_product_slug": null
    },
    "page": 1,
    "per_page": 2,
    "total": 2
  },
  "links": {
    "self": "http://localhost/v1/orders/media-status?session_start=2025-09-01&session_end=2025-10-31"
  },
  "trace_id": "...."
}
```

> **Observacao**: os blocos em `media_status.gallery` e `media_status.game` refletem o JSON original do `status.php` (incluindo `stats`, `pastas` e `fotos`) e acrescentam campos calculados como `computed.total_photos`, `computed.media_por_pasta` e `folder_ids` ja normalizados (strings ordenadas).

---

## Campos de interesse

### `data[]`

| Campo | Descricao |
|-------|-----------|
| `id` | ID do pedido no CRM. |
| `schedule_1` | Data/hora da sessao. |
| `status_name` | Nome do status atual do pedido. |
| `product_name` | Primeiro pacote (`bundle=true`) ou, na falta, o primeiro item do pedido. |
| `in_gallery` | `true` se existe pasta com o mesmo ID na galeria. |
| `in_game` | `true` se existe pasta com o mesmo ID no game. |

### `summary.kpis`

* `total_imagens`: total de fotos informado pela **galeria** (`stats.total_fotos`).  
* `media_fotos`: media informada pela galeria (`stats.media_por_pasta`, arredondada para 2 casas).  
* `total_galerias_ativas`: total de pastas validas da galeria.  
* `total_jogos_ativos`: total de pastas validas do game.

### `summary.orders`

* `with_gallery` / `without_gallery`: pedidos retornados que possuem (ou nao) pasta na galeria.  
* `with_game` / `without_game`: mesmo conceito para o game.

### `summary.media.gallery | summary.media.game`

Cada objeto possui:

* `total_photos` / `total_photos_calculated`: total informado pelo status.php e total recalculado (soma de `total_arquivos`).  
* `media_por_pasta`: media original do status.php.  
* `average_photos_per_folder`: media recalculada pela API (fallback quando a media original estiver ausente).  
* `orders_with_media` / `orders_without_media`: cruzamento com os pedidos retornados.  
* `folder_ids`: lista das pastas encontradas (strings ordenadas).  
* `pastas`: espelho do array recebido no status.php, incluindo a relacao de fotos.

### `meta.filters`

* `product_slug`: slug normalizado efetivamente usado na consulta (`null` quando nao houve filtro).  
* `default_product_slug`: valor padrao que a API utilizara caso nenhum slug seja informado.  
* `requested_product_slug`: valor recebido na query string, apos `trim` (pode ser `null` ou `*`).  
* `session_start`, `session_end`: parametros utilizados na execucao.

---

## Comportamentos e logs

* A API registra logs `orders.media_status.collect.start` e `orders.media_status.collect.success` com `trace_id`, intervalo e slug utilizado.  
* Erros no CRM geram log `orders.media_status.crm_*` e a resposta volta com `summary.warnings[]` descrevendo o problema.  
* As consultas a `status.php` possuem cache interno (memoria/local ou Redis) de 60 segundos para evitar sobrecarga.

---

## Tratamento de erros comuns

| Codigo | Causa | Acao |
|--------|-------|------|
| 422 | Datas invalidas ou fora da janela permitida | Validar antes de enviar; repetir com valores corretos. |
| 502 | Timeout ou indisponibilidade do CRM | Avisar o usuario e permitir nova tentativa; monitorar os logs com o `trace_id`. |
| 500 | Falha inesperada | Retentar e reportar; verificar se `CRM_TOKEN` esta configurado. |

---

## Checklist rapido para o front

1. Chamar `/v1/orders/search` com `order[session-start]`, `order[session-end]` e `product[slug]` (natal ou outro slug desejado).  
2. Remover cancelados (`status.id = 1` ou `status.name = "Pedido Cancelado"`).  
3. Chamar `/v1/orders/media-status` com os mesmos filtros.  
4. Mesclar as informacoes de ambos os retornos (campos ricos do CRM + flags de midia).  
5. Exibir os KPIs (`summary.kpis`) e contadores (`summary.orders`) no topo do dashboard.  
6. Para detalhamento, utilizar `media_status.gallery.pastas[]` e `media_status.game.pastas[]`, que trazem a lista de arquivos exatamente como no status.php original.

---

Em caso de duvidas ou necessidade de novos campos, alinhe com o time de back-end antes de modificar o contrato.
*** End Patch
