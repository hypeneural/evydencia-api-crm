# Guia de Consumo do Front para os Endpoints de Agendamento de Status do WhatsApp

Este guia consolida tudo o que o front-end precisa para integrar os fluxos de agendamento de Status do WhatsApp (`/v1/scheduled-posts`), com foco em robustez, observabilidade e boa experiência de usuário. As recomendações abaixo foram extraídas do código fonte atual (`app/Actions/ScheduledPosts`, `app/Application/Services/ScheduledPostService`, `app/Application/Support/QueryMapper`, `app/Infrastructure/Persistence/PdoScheduledPostRepository`) e dos materiais de referência em `docs/scheduled-posts.md` e `docs/scheduled-posts-front-prompt.md`.

---

## 1. Preparação Ambiente

- **Base URL (prod):** `https://api.evydencia.com.br`
- **Base URL (local dev):** `http://localhost:8080/api`
- **Autenticação:** enviar `X-API-Key` em todas as requisições (middleware `ApiKeyMiddleware` bloqueia 401).
- **Headers úteis:**  
  - `Trace-Id` (opcional): qualquer string; se omitido, o backend gera e devolve via `X-Request-Id`.  
  - `If-None-Match`: usar em cache condicional da listagem com o ETag retornado (detalhe em §3.3).
- **Fuso obrigatório:** todas as datas transmitem/recebem em `America/Sao_Paulo` no formato `YYYY-MM-DD HH:MM:SS`. Converta sempre no front antes de enviar.
- **Env front:** centralize host/API key em config (por exemplo `import.meta.env.VITE_API_BASE`, `VITE_API_KEY`) e exponha util para ler.

---

## 2. Contrato de Respostas

Todos os handlers retornam envelopes padronizados via `ApiResponder`:

```json
// Sucesso
{
  "success": true,
  "data": { ... },
  "meta": { ... },
  "links": { "self": "...", "next": null, "prev": null },
  "trace_id": "..." // quando aplicável
}

// Erro
{
  "success": false,
  "error": {
    "code": "unprocessable_entity|internal_error|unauthorized|...",
    "message": "Mensagem humana",
    "errors": [ { "field": "nome", "message": "detalhe" } ] // opcional
  },
  "trace_id": "..."
}
```

Boas práticas no front:

- Sempre verificar `response.success`.  
- Em `422` iterate `error.errors` para mapear mensagens por campo (já vem em português).  
- Logar no console um resumo incluindo `trace_id` – isso facilita correlacionar com os logs do servidor (`app/Middleware/RequestLoggingMiddleware`, `var/logs/app.log`).  
- Em `500` exibir fallback amigável e oferecer botão de retry; os logs anteriores mostravam `message: "Nao foi possivel carregar os indicadores."` para analytics quando a consulta SQL falhava.

---

## 3. Convenções Compartilhadas pelos Endpoints

### 3.1 Query params normalizados

`QueryMapper::mapScheduledPosts()` controla o parsing, portanto a UI deve falar a mesma língua:

| Parâmetro                  | Tipo front-end                        | Observações |
|----------------------------|---------------------------------------|-------------|
| `page`                     | inteiro ≥ 1                           | default 1 |
| `per_page`                 | inteiro 1–200                         | default 50 |
| `fetch`                    | string `"all"`                        | retorna todos os itens sem paginação |
| `sort[field]`              | string                                | aceitar qualquer coluna conhecida (`scheduled_datetime`, `id`, etc.) |
| `sort[direction]`          | `"asc"` ou `"desc"`                   | default `asc` |
| `fields`                   | string `fields=id,type`               | limita campos retornados (use para listas leves) |
| `q`                        | string                                | search em `message` ou `caption` |
| `filter[type]`             | `"text" | "image" | "video"`          | aceita inline `type=text` também |
| `filter[status]`           | `"pending" | "scheduled" | "sent" | "failed"` | deriva condição SQL preparada (`buildStatusCondition`) |
| `filter[has_media]`        | boolean/string (`true`/`false`)       | filtra por presença de mídia |
| `filter[caption_contains]` | string                                | `LIKE %valor%` |
| `filter[message_id_state]` | `"null" | "not_null"`                 | mapeia `messageId` vazio/cheio |
| `filter[scheduled_datetime][gte|lte]` | string datetime           | também aceitam atalhos `scheduled_datetime_gte`/`_lte` |
| `filter[created_at][gte|lte]` | string datetime                   | idem |
| `filter[scheduled_today]`  | boolean                               | força `DATE(scheduled_datetime)=CURRENT_DATE` |
| `filter[scheduled_this_week]` | boolean                           | usa `YEARWEEK` |
| `messageId`                | string                                | também parseado em `filter[messageId]` |

O front deve construir os filtros sempre como strings simples (evite arrays aninhados) – o mapper converte para estrutura final usada pelo repositório (`PdoScheduledPostRepository::buildFilterClause`).

### 3.2 Headers de paginação e ligações

- Listagem devolve `X-Total-Count` e `meta.total`, `meta.total_pages`, `links.next/prev`.  
- `HandlesListAction::buildLinks()` utiliza o `Request` original, portanto o front pode reutilizar `meta` e `links` para criar paginação e exibir o total.

### 3.3 Cache condicional via ETag

- `ListScheduledPostsAction` gera um `signature` com filtros, página, sort, fetch e salva no `ScheduledPostCache`.  
- Se o front setar `If-None-Match` com o ETag recebido e nada mudar, o backend responde `304` sem payload.  
- Para aproveitar: armazenar `lastEtag` por chave de query (React Query key) e reusar no header antes da chamada. Em caso de `304`, usar dados do cache client-side e não sobrescrever com vazio.

### 3.4 Tratamento de mutações

Sempre que executar `POST/PUT/PATCH/DELETE/BULK`, a UI deve:
1. Invalida cache da lista (`GET /v1/scheduled-posts`) — o cache do servidor também é invalidado no service (`ScheduledPostService::create/update/delete/bulk...` chamam `ScheduledPostCache::invalidate()`).
2. Invalida cache do analytics (`GET /v1/scheduled-posts/analytics`) para atualizar métricas.
3. Opcional: refetch de `GET /v1/scheduled-posts/ready` se exibir badge de fila.

---

## 4. Endpoint: GET /v1/scheduled-posts (Listagem)

### 4.1 Responsabilidade do front
- Montar data table com colunas mínimas: tipo, mensagem/caption, data agendada, status (derivado de `messageId` & `scheduled_datetime`), id.  
- Exibir filtros avançados com base em `meta.available_filters`:
  - `types`: array de tipos suportados.
  - `statuses`: array com status válidos.  
- Usar `meta.filters_applied` para refletir seleção atual (chip/lista de filtros aplicados na UI).
- Honrar `meta.elapsed_ms` para monitoramento (pode ser exibido em devtools).

### 4.2 Paginação, seleção e ordenação
- Padrão: React Query key `['scheduled-posts', params]`.  
- Paginador: utilize `meta.page`, `meta.per_page`, `meta.total_pages`.  
- Ordenação: UI deve permitir múltiplos sorts? (backend aceita lista via string `scheduled_datetime,-id`; hoje o mapper retorna array, mas a API aceita string também. Para simplicidade mantenha interface single sort com `sort[field]` + `sort[direction]`).  
- Seleção de colunas: se a lista carregar muitos campos, usar `fields` para limitar. Ex.: `fields=id,type,scheduled_datetime,messageId`.

### 4.3 Estados e mensagens
- Empty state: se `data.length === 0`, mostrar call-to-action para criar novo agendamento.  
- Loading state: skeletons enquanto `ReactQueryResult.isLoading` e sem dados cacheados.  
- Error state: exibir mensagem `Falha ao carregar agendamentos` + botão `Tentar novamente`.
- Logging: console com `[API Request] trace_id ...` (código existente em `scheduled-posts.ts` já faz isso, mantenha).  
- Perf: o log `"[Violation] 'message' handler took 162ms"` vem de handlers de mensagem no Vite devtools; assegurar que handlers do front sejam otimizados (debounce em search, etc.).

---

## 5. Endpoint: GET /v1/scheduled-posts/analytics

### 5.1 O que retorna
`ScheduledPostService::analytics()` retorna:

| Campo                     | Tipo            | Uso sugerido na UI |
|---------------------------|-----------------|--------------------|
| `summary.total|sent|pending|scheduled|failed` | inteiros | Cards principais |
| `success_rate`            | número (% com 1 casa) | Indicador de performance |
| `by_type`                 | `{text,image,video}`  | Gráfico de barras / pizza |
| `by_date[]`               | até 30 buckets `{date, sent, scheduled, failed}` | Gráfico de área/linha |
| `recent_activity.last_sent/last_created` | string datetime / null | Tooltip “Último envio/criação” |
| `recent_activity.sent_last_30min/sent_today` | inteiros | KPI rápido |
| `upcoming.next_hour/next_24h/next_7days` | inteiros | Cards “Agendados por janela” |
| `performance.avg_delivery_time_seconds` | float ou null | Converter para minutos/tempo legível |
| `performance.avg_processing_time_seconds` | float ou null | Idem |
| `meta.filters_applied`    | array associativo | Chips ativos |
| `meta.available_filters`  | `types`/`statuses` | Preencher selects no painel de analytics |

### 5.2 Boas práticas

- Reutilizar a mesma estrutura de filtros/paginação da lista (query params idênticos).  
- Construir dashboards resilientes:
  - Se algum campo vier `null`, exibir placeholder “–” em vez de zero.
  - Para `by_date`, ordenar cronologicamente antes de renderizar caso precise do mais recente à direita.  
- Manter logs `[API Request] trace_id ...` (importante ao debugar 500).  
- Em erros (500/422): mostrar aviso “Não foi possível carregar os indicadores” com ação de retry; também invalidar após 30s para tentar novamente automaticamente.
- Se a resposta vier com `success=true` mas `summary.total=0`, exiba empty state otimista (por exemplo “Nenhum agendamento encontrado para os filtros atuais”).  
- Cache: React Query `staleTime` curto (ex. 30 s) para não inundar o backend. Armazene o resultado no mesmo key set usado para lista (`filters` compartilhados) para atualizar simultaneamente após mutações.

---

## 6. Outras Rotas Importantes

| Rota                                       | Uso no front                                                      | Observações |
|-------------------------------------------|------------------------------------------------------------------|-------------|
| `POST /v1/scheduled-posts`                | Criar agendamento                                                 | Após sucesso, refetch lista + analytics. Backend já invalida cache. |
| `POST /v1/scheduled-posts/media/upload`   | Upload de imagem/vídeo                                            | Respeitar limites de tamanho (5 MB imagem, 10 MB vídeo). Exibir progresso e validar MIME antes de enviar. |
| `GET /v1/scheduled-posts/{id:[0-9]+}`     | Carregar dados para edição                                        | Rota exige `id` numérico (regex). |
| `PATCH /v1/scheduled-posts/{id:[0-9]+}`   | Atualizar                                                         | Mandar somente campos alterados. |
| `DELETE /v1/scheduled-posts/{id:[0-9]+}`  | Excluir item único                                                | Confirm dialog → success toast → refetch. |
| `PATCH /v1/scheduled-posts/bulk`          | Edição em massa (caption, datetime)                               | Validar payload no front (ids array + campos permitidos). |
| `DELETE /v1/scheduled-posts/bulk`         | Exclusão em massa                                                 | Backend retorna `deleted`, `failed`, `errors`. Mostrar resumo ao usuário. |
| `POST /v1/scheduled-posts/bulk/dispatch`  | Disparo manual em lote                                            | Exibir relatório retornado (`items[]`). |
| `POST /v1/scheduled-posts/{id:[0-9]+}/duplicate` | Duplicar um agendamento                                  | Ideal para botão “Duplicar” na UI. |
| `POST /v1/scheduled-posts/{id:[0-9]+}/mark-sent` | Marcar como enviado manualmente                             | Requer `messageId` no corpo; validar antes de enviar. |

---

## 7. Observabilidade e Debug

- **Trace IDs:** o middleware de logging usa `trace_id`, portanto salvar no console (`console.info('[API Request]', { trace_id, url, status })`) é fundamental.  
- **Tempo de execução:** `meta.elapsed_ms` ajuda a detectar requisições lentas; use no devtools overlay.  
- **Rate limiting:** `RateLimitMiddleware` adiciona cabeçalhos `X-RateLimit-*`; se o front receber 429, exiba mensagem e use exponencial backoff.  
- **Logs anteriores do front:** os erros citados (`500` na analytics com trace `47cee870ce923042`) vieram de uma falha SQL já corrigida. Contudo, mantenha monitoramento para regressões.  
- **Hot reload / Vite warnings:** o aviso `'message' handler took 162ms` indica callback caro. Considere memoizar handlers e reduzir re-renders (React Profiler).

---

## 8. Fluxo Recomendo no Front (React Query)

1. **Hooks compartilhados**  
   ```ts
   const listQuery = useQuery(['scheduled-posts', params], () => api.listScheduledPosts(params), {
     staleTime: 30_000,
     onSuccess: (data) => console.info('[API Request] trace_id:', data.trace_id),
     onError: (error) => console.error('[API Error]', error.trace_id, error),
   });

   const analyticsQuery = useQuery(['scheduled-posts-analytics', params], () => api.getAnalytics(params), {
     staleTime: 30_000,
     retry: (failureCount, error) => (error.status === 500 && failureCount < 2),
   });
   ```

2. **Mutations**  
   Ao concluir `create/update/delete/bulk/duplicate`, use `queryClient.invalidateQueries` para as chaves da lista, analytics e ready queue.

3. **Headers dinâmicos**  
   Guardar `Etag` retornado pela lista e reaproveitar em `If-None-Match` no próximo fetch (usar `queryClient.getQueryData` para acessar e salvar junto aos dados).

4. **Conversão de data**  
   Sempre converter inputs do usuário (picker local) para timezone São Paulo (`dayjs.tz(..., 'America/Sao_Paulo').format('YYYY-MM-DD HH:mm:ss')`), e o inverso ao renderizar.

---

## 9. Checklist de Robustez

- [ ] `X-API-Key` configurado em toda request (Axios interceptor ou fetch wrapper).  
- [ ] `Trace-Id` logado em todos os requests/respostas.  
- [ ] Tratamento de `304` com reutilização de cache.  
- [ ] Filtros e sort sincronizados com URL (permite bookmarks e compartir estado).  
- [ ] Retry automático (máx. 2) para `500` e `502`, com toast informativo.  
- [ ] Empty/error/loading states distintos na lista e no dashboard.  
- [ ] Atualização da analytics após qualquer mutação relevante.  
- [ ] Conversão correta de timezone nas telas de criação/edição.  
- [ ] Validação cliente alinhada às regras do backend (tipos, obrigatoriedade de mídia, limites de tamanho).  
- [ ] Observabilidade: logs consolidados, métricas (tempo de carga) e reporte de falhas crítico via Sentry/LogRocket.

Seguindo estas orientações, o front-end fica alinhado ao contrato real da API e resiliente a variações de dados ou falhas temporárias, reduzindo ocorrências de erros 500/422 visíveis ao usuário final.
