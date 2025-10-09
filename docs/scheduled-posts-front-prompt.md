# Scheduled Posts Front-End Prompt

Use este prompt como guia completo para implementar o consumo de todos os endpoints de agendamentos de Status do WhatsApp. Ele cobre autenticação, esquemas de payloads, fluxos de upload/agendamento e operações em massa.

---

## 1. Contexto Geral
- **Base URL**: `https://api.evydencia.com.br`
- **Ambiente local**: `http://localhost:8080/api`
- **Autenticação**: Header obrigatório `X-API-Key: <key>` em todas as requisições. Opcional `Trace-Id` para correlação de logs.
- **Formato de datas**: Sempre enviar e interpretar como string `"YYYY-MM-DD HH:MM:SS"` na timezone `America/Sao_Paulo`.
- **Envelope padrão**:
  ```json
  // sucesso
  { "success": true, "data": ..., "meta": ..., "links": ..., "trace_id": "..." }

  // erro
  { "success": false, "error": { "code": "...", "message": "...", "errors": [...] }, "trace_id": "..." }
  ```
- **Tratamento de erros**:
  - `422` contém `error.errors[]` com `field` + `message` (mapear para inputs).
  - `502` indica falha ao disparar via Z-API (exibir mensagem amigável e permitir retry).
  - `trace_id` sempre presente → logar no DevTools para debugar.

---

## 2. Fluxo End-to-End (Agendamento de Status)
1. **Upload opcional de mídia**  
   - Endpoint: `POST /v1/scheduled-posts/media/upload`
   - Multipart `type=image|video`, campo `media` com `File`.  
   - Validar tamanho localmente (imagens ≤ 5 MB, vídeos ≤ 10 MB).  
   - Persistir `data.url` para usar no agendamento e `data.relative_path` se precisar exibir preview.

2. **Configuração do agendamento**  
   - Formulário inclui `type`, `message`, `caption`, `scheduled_datetime`, `image_url`/`video_url`.  
   - Converter data/hora do usuário para `"YYYY-MM-DD HH:MM:SS"` (TZ São Paulo).  
   - Regras:
     - `type="text"` → `message` obrigatório; `image_url`/`video_url` nulos.
     - `type="image"` → `image_url` obrigatório.
     - `type="video"` → `video_url` obrigatório.
   - Chamar `POST /v1/scheduled-posts` com JSON correspondente.

3. **Resposta pós-criação**  
   - Se `scheduled_datetime <= now`, o backend tenta disparar imediatamente e retorna `messageId/zaapId` quando houver sucesso.  
   - Em caso de falha de envio imediato (`502`), exibir alerta e permitir reprocessar via worker/ação manual.

4. **Listagem / Monitoramento**  
   - `GET /v1/scheduled-posts` com paginação, filtros e ordenação.  
   - Consumir `meta.filters_applied` para exibir chips ativos.  
   - `available_filters` devolve listas (`types`, `statuses`) para popular dropdowns.  
   - Usar `links.next/prev` ou paginação local com `page`/`per_page`.

5. **Despacho manual**  
   - Botão “Processar agora” chama `POST /v1/scheduled-posts/bulk/dispatch` com `ids` selecionados.  
   - Exibir `data.summary` (counts) + `data.items[]` (status individual) e listar `errors` (IDs não encontrados).

6. **Edição / Atualização**  
   - `GET /v1/scheduled-posts/{id}` para carregar formulário.  
   - `PATCH /v1/scheduled-posts/{id}` com campos alterados.  
   - Ao trocar mídia, subir novo arquivo e substituir URL.

7. **Remoção**  
   - Individual: `DELETE /v1/scheduled-posts/{id}` com confirmação.
   - Em lote: `DELETE /v1/scheduled-posts/bulk` com array de IDs.

8. **Duplicação**  
   - `POST /v1/scheduled-posts/{id}/duplicate` com overrides opcionais (`scheduled_datetime`, `caption`, etc.).  
   - Backend retorna novo recurso e `original_id` para rastrear origem.

---

## 3. Endpoints e Schemas
### 3.1 Upload de Mídia (`POST /v1/scheduled-posts/media/upload`)
```http
POST /v1/scheduled-posts/media/upload
Headers:
  X-API-Key: <key>
Body (multipart):
  type=image|video
  media=<File>

201 Response:
{
  "success": true,
  "data": {
    "type": "image",
    "url": "https://api.evydencia.com.br/status-media/image/uuid.png",
    "relative_path": "image/uuid.png",
    "mime_type": "image/png",
    "size": 1345
  },
  ...
}
```

### 3.2 Criar Agendamento (`POST /v1/scheduled-posts`)
```json
{
  "type": "image",
  "message": null,
  "image_url": "https://api.evydencia.com.br/status-media/image/launch.png",
  "video_url": null,
  "caption": "Lançamento",
  "scheduled_datetime": "2025-10-20 09:30:00"
}
```
- Resposta `200` com recurso completo.  
- Tratar erros `422` (exibir mensagens por campo).  
- Se houver `messageId`, já consta como enviado.

### 3.3 Listagem (`GET /v1/scheduled-posts`)
Parâmetros suportados:
- `page`, `per_page` (1–200)
- `fetch=all`
- `sort[field]`, `sort[direction]` ou `sort=-scheduled_datetime`
- `search=<texto>`
- `filters[type]=text|image|video`
- `filters[status]=pending|scheduled|sent|failed`
- `filters[scheduled_datetime][gte|lte]=...`
- `filters[created_at][gte|lte]=...`
- `filters[has_media]=true|false`
- `filters[caption_contains]=...`
- `filters[scheduled_today]=true`
- `filters[scheduled_this_week]=true`
- `filters[message_id_state]=null|not_null` (legacy)

Usar dados do envelope:
- `data[]` com registros (inclui flag `has_media`).
- `meta.page/per_page/count/total/total_pages/source`.
- `meta.filters_applied` → renderizar filtros ativos.
- `meta.available_filters` → tipos e statuses possíveis.
- `links.self/next/prev` → paginação baseada em URLs.

### 3.4 Métricas / KPIs (`GET /v1/scheduled-posts/analytics`)
- Mesmos filtros da listagem.  
- `data.summary`: totals (total, sent, pending, scheduled, failed).  
- `success_rate`: porcentagem (sent/total).  
- `by_type`: distribuição por `text|image|video`.  
- `by_date`: últimos 30 buckets por dia com contagem `sent|scheduled|failed`.  
- `recent_activity`: `last_sent`, `last_created`, `sent_last_30min`, `sent_today`.  
- `upcoming`: `next_hour`, `next_24h`, `next_7days`.  
- `performance`: tempos médios em segundos (`avg_delivery_time_seconds`, `avg_processing_time_seconds`).  
- `meta.filters_applied`/`available_filters` idênticos à listagem.

### 3.5 Atualizar (`PATCH /v1/scheduled-posts/{id}`)
- Enviar somente campos alterados.  
- Mesmas validações de criação.  
- `PUT` também suportado para updates completos.

### 3.6 Deletar (`DELETE /v1/scheduled-posts/{id}` / `/bulk`)
- Individual retorna `{ "success": true, "data": [] }`.  
- Bulk retorna `{ deleted, failed, errors[] }`, onde `errors` lista IDs inexistentes.

### 3.7 Bulk Update (`PATCH /v1/scheduled-posts/bulk`)
- Payload obrigatório:
  ```json
  {
    "ids": [1, 2, 3],
    "updates": {
      "scheduled_datetime": "2025-10-20 10:00:00",
      "caption": "Nova legenda opcional"
    }
  }
  ```
- Apenas `scheduled_datetime` e `caption` permitidos por enquanto.
- Resposta inclui contagem `updated`, `failed`, `errors`.

### 3.8 Bulk Dispatch (`POST /v1/scheduled-posts/bulk/dispatch`)
- Payload: `{ "ids": [ ... ] }`.  
- Resposta traz:  
  - `summary`: `requested`, `processed`, `sent`, `failed`, `skipped`, `missing`.  
  - `items[]`: resultado individual (`status`, `messageId`, `zaapId`, `error`).  
  - `errors[]`: IDs não encontrados.

### 3.9 Worker Dispatch (`POST /v1/scheduled-posts/worker/dispatch`)
- Opcional `limit`.  
- Serve para cron (ex.: `*/5 * * * * curl -X POST ...`).  
- Front pode usar manualmente se quiser processar toda fila pendente.

### 3.10 Duplicate (`POST /v1/scheduled-posts/{id}/duplicate`)
- Body opcional com campos a sobrescrever (ver Seção 5.2).  
- Resposta inclui `original_id`.

### 3.11 Ready Queue (`GET /v1/scheduled-posts/ready`)
- Retorna lista de posts prontos para disparo (`limit` padrão 50).  
- Útil para exibir “Pendentes agora”.

### 3.12 Mark as Sent (`POST /v1/scheduled-posts/{id}/mark-sent`)
- Payload mínimo: `{ "messageId": "...", "zaapId": "..." }`.  
- Usado por webhooks/ops manuais quando Z-API confirmar envio.  
- Necessário `messageId` para conciliar disparos.

---

## 4. Lógica do Front-End
### 4.1 Fluxo de Criação / Edição
1. Se o usuário anexar mídia:
   - Validar tipo/tamanho antes do upload.
   - Enviar `POST /media/upload` e mostrar spinner/percentual.
   - Salvar `url` e `relative_path` na store/form state.
2. Coletar campos do formulário.
3. Converter data/hora para string correta (`dayjs.tz` ou equivalente).
4. Montar payload conforme o tipo.
5. Enviar para `POST /v1/scheduled-posts` ou `PATCH /v1/scheduled-posts/{id}`.
6. On success, limpar formulário e recarregar lista/analytics.
7. On error:
   - Se `422`, mapear `error.errors` para exibir por campo.
   - Se `502`, exibir mensagem com call-to-action para reenfileirar.

### 4.2 Listagem e Filtros
- Persistir estado de filtros na URL/query string.  
- Aplicar `filters[...]` no request (usar `URLSearchParams`).  
- Atualizar `meta.filters_applied` → renderizar badges/chips.  
- Exibir `available_filters` como fonte de valores (evita hardcode).  
- Para ordenação dinâmica, enviar `sort[field]` e `sort[direction]`.  
- Para baixa latência, usar debounce em `search`.

### 4.3 Operações em Massa
- Usar checkboxes na grid e estado global de seleção.  
- **Bulk Delete**: confirmar ação, chamar `DELETE /bulk`. Atualizar lista removendo IDs.  
- **Bulk Update**: abrir modal com campos permitidos (datetime, caption). Validar antes de enviar.  
- **Bulk Dispatch**: exibir resultado em modal/painel (sucesso, falhas, `errors`). Permitir baixar JSON/CSV se necessário.  
- Após cada operação em massa, atualizar analytics/listagem (cache backend é invalidado automaticamente).

### 4.4 Dashboard / KPIs
- Chamar `GET /analytics` com os mesmos filtros aplicados na listagem (sincronizar state).  
- Exibir:
  - Cards (`summary.*`, `success_rate`).  
  - Gráfico por tipo (`by_type`).  
  - Série temporal (`by_date`).  
  - Indicadores (`recent_activity`, `upcoming`).  
  - Tempos médios (`performance`).  
- Atualizar KPIs a cada refresh de filtro ou intervalo (ex.: 60s).

### 4.5 Tratamento de Estados
- `status` calculado no front com base em campos:
  - `messageId` presente → enviado.
  - `scheduled_datetime` > `now` → agendado.
  - `scheduled_datetime` <= `now` e sem `messageId` → pendente/atrasado.
  - Backend já retorna KPIs com grace period de 10 minutos para classificar `failed`; usar isso nos dashboards.

### 4.6 Rastreamento / Logs
- Incluir `Trace-Id` (UUID) por sessão/aba para correlacionar chamadas.  
- Mostrar `trace_id` em toasts ou console quando houver erro crítico.  
- Monitorar `X-Total-Count` das listagens para paginação customizada.

---

## 5. Esquemas Resumidos
### 5.1 Scheduled Post Record (lista/detalhe)
```json
{
  "id": 123,
  "type": "image",
  "message": null,
  "image_url": "https://.../status-media/image/foo.png",
  "video_url": null,
  "caption": "Legenda opcional",
  "scheduled_datetime": "2025-10-20 09:30:00",
  "zaapId": null,
  "messageId": null,
  "created_at": "2025-10-07T18:15:20.000000Z",
  "updated_at": "2025-10-07T18:15:20.000000Z",
  "has_media": true
}
```

### 5.2 Duplicate Overrides Payload
```json
{
  "scheduled_datetime": "2025-10-22 08:00:00",
  "caption": "Nova legenda",
  "message": "Mensagem opcional",
  "type": "image",
  "image_url": "https://.../status-media/image/new.png",
  "video_url": null
}
```

### 5.3 Bulk Dispatch Result Item
```json
{
  "id": 321,
  "type": "text",
  "scheduled_datetime": "2025-10-10 10:00:00",
  "status": "sent",
  "messageId": "3EB0...==",
  "zaapId": "ZXCV-123",
  "provider_status": 200,
  "error": null
}
```

---

## 6. Boas Práticas para o Front
- Centralizar headers de autenticação em um client HTTP (axios interceptors/fetch wrapper).  
- Implementar retry automático (com limite) para `502` e `503`.  
- Debounce de 300–500 ms em buscas.  
- Armazenar configurações de filtros no local storage para persistência.  
- Validar tamanho e tipo da mídia antes do upload para evitar round-trips inúteis.  
- Mostrar contadores/feedback após operações em massa (usar dados de `summary`).  
- Atualizar KPIs/listagens após ações CRUD (backend invalida cache em cada mutação).  
- Adotar feature flags se precisar liberar funcionalidade gradualmente (bulk ops/duplica).  
- Monitorar índices `created_at`/`scheduled_datetime` para ofertas de filtros rápidos (ex.: chips “Hoje”, “Semana”).  
- Usar timezone awareness nas libs de data (dayjs/moment com plugin timezone).

---

## 7. Checklist Rápido por Feature
- [ ] Upload concluído antes de habilitar “Agendar”.
- [ ] Formato datetime correto (`America/Sao_Paulo` → string).  
- [ ] Campos obrigatórios por tipo.  
- [ ] Exibir `trace_id` ao debugar erros.  
- [ ] Sincronizar filtros entre lista e analytics.  
- [ ] Mostrar resultados das ações em massa (`summary`/`errors`).  
- [ ] Permitir duplicação com overrides amigáveis.

---

### Prompt rápido (resumido)
> “Implemente um front-end para agendamentos de status do WhatsApp consumindo a API em `https://api.evydencia.com.br`. Toda requisição deve incluir `X-API-Key`. Siga o fluxo: upload opcional (`POST /v1/scheduled-posts/media/upload`), criação (`POST /v1/scheduled-posts`), listagem com filtros avançados (`GET /v1/scheduled-posts`), dashboards (`GET /v1/scheduled-posts/analytics`), operações em massa (`/bulk` delete/update/dispatch) e duplicação (`POST /v1/scheduled-posts/{id}/duplicate`). Converta datas para `YYYY-MM-DD HH:MM:SS` em timezone São Paulo. Trate envelopes `success/error`, exiba mensagens por campo em `422`, e use `meta.filters_applied`/`available_filters` para UI. Após mutações, recarregue lista e analytics. Registre `trace_id` no console para debug.”

