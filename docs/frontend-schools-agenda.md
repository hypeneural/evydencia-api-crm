# Guia de Consumo Frontend - Escolas e Agenda

Este guia explica como o aplicativo (web/mobile) deve consumir os endpoints do modulo de escolas e de agenda, cobrindo filtros, respostas e praticas recomendadas de performance - com foco especial no funcionamento offline.

## Convencoes Gerais
- **Base URL**: todas as rotas estao sob `/api/v1` e exigem o header `X-API-Key` valido.
- **Envelope padrao**: as respostas bem sucedidas seguem `{ success, data, meta, links, trace_id }`. Erros retornam `{ success: false, error: { code, message, ... }, trace_id }`.
- **Paginacao**: `page` (>=1) e `per_page` (1..100). Use `meta.total`, `meta.total_pages`, `links.next`/`links.prev` para paginacao progressiva.
- **Cache-Control / ETag**: algumas rotas (agregadores de cidades/bairros) fornecem `meta.etag`. Armazene o valor e reaproveite em requests condicionais (`If-None-Match`) para reduzir trafego.
- **Traceability**: guarde `trace_id` em logs do app; facilita debug junto ao backend.

---

## Modulo de Escolas

### Visao Geral de Endpoints
| Verbo | Rota | Uso principal |
|-------|------|---------------|
| GET | `/cidades` | Agregados por cidade (totais, etapas, turnos). `includeBairros=true` carrega bairros embutidos. |
| GET | `/cidades/{id}/bairros` | Agregados do municipio; `withEscolas=true` inclui resumos de escolas. |
| GET | `/escolas` | Listagem paginada com busca e filtros. |
| GET | `/escolas/{id}` | Detalhe completo de uma escola. |
| PATCH | `/escolas/{id}` | Atualizacao otimista (panfletagem, observacoes rapidas, indicadores). |
| GET | `/escolas/{id}/panfletagem/logs` | Historico paginado de alteracoes de panfletagem. |
| GET | `/escolas/{id}/observacoes` | Historico de observacoes. |
| POST | `/escolas/{id}/observacoes` | Criacao de observacao. |
| PUT | `/escolas/{id}/observacoes/{obsId}` | Atualizacao de observacao existente. |
| DELETE | `/escolas/{id}/observacoes/{obsId}` | Exclusao de observacao. |
| GET | `/filtros/cidades` | Opcoes para filtro (com totais e pendencias). |
| GET | `/filtros/bairros` | Opcoes de bairro; `includeTotals=true` retorna totais. |
| GET | `/filtros/periodos` | Lista estatica de periodos (`Matutino`, `Vespertino`, `Noturno`). |
| GET | `/kpis/overview` | KPIs agregados (total de escolas, pendencias, etapas, turnos). |
| GET | `/kpis/historico` | Serie diaria de panfletagem (feitas x pendentes). |
| POST | `/sync/mutations` | Fila de mutacoes offline (`tipo`/`type`, `payload`/`updates`). |
| GET | `/sync/changes` | Delta incremental para sincronizacao offline. |

### Listagens e Filtros
- **`GET /escolas`** aceita:
  - `search`: busca em nome, diretor, endereco.
  - `filter[cidade_id][]`, `filter[bairro_id][]`, `filter[tipo][]` (exemplo `CEI`, `CMEI`).
  - `filter[status]`: `pendente`, `feito`, `todos` (baseado em panfletagem).
  - `filter[periodos][]`: `Matutino`, `Vespertino`, `Noturno`.
  - `sort`: campos separados por virgula (`nome`, `-total_alunos`, `panfletagem`, etc.).
  - `fetch=all`: apenas para usuarios internos (evitar em mobile offline - pode gerar payload massivo).
- Resposta inclui `periodos` como array, `indicadores` (flags `tem_*`), `versao_row` para controle otimista e timestamps ISO8601 UTC (`atualizado_em`).
- Para melhor UX: mantenha cache local (por cidade/bairro) com timestamp; recarregue apenas quando usuario mudar filtros ou ao receber atualizacao via `/sync/changes`.

### Detalhes e Atualizacoes (PATCH)
- Use `GET /escolas/{id}` para carregar payload completo (`SchoolDetail`) antes de editar.
- `PATCH /escolas/{id}` requer:
  1. Header `If-Match: <versao_row>` com valor atual.
  2. Campo `versao_row` dentro do corpo (repita o mesmo valor).
  3. Apenas envie campos alterados (ex.: `panfletagem`, `obs`, `periodos`, `indicadores`).
- Em caso de `409 conflict`:
  - Backend retorna payload atualizado; substitua localmente e ofereca tela de merge ou tente novamente com nova versao.
  - No modo offline, guarde a mutacao (ver secao Sync) e reenvie ao voltar online; se conflito persistir, exiba diff ao usuario.

### Observacoes e Logs
- **Observacoes** (`/observacoes`):
  - Paginacao padrao (`page`, `per_page`).
  - Criacao (`POST`) aceita `{ "observacao": "texto" }`. Salve `id` retornado para edicoes futuras.
  - Atualizacao (`PUT`) idem ao POST; exclusao via `DELETE` (retorno 204).
- **Logs de panfletagem** (`/panfletagem/logs`):
  - Util para auditoria e timeline de campo.
  - Cada item contem `status_anterior`, `status_novo`, `observacao`, `usuario` (id/nome) e `criado_em`.
  - Prefetch somente quando o usuario abrir detalhes (para evitar trafego desnecessario).

### Filtros e Agregadores
- **`GET /cidades`** e **`GET /cidades/{id}/bairros`** expoem totais de escolas, alunos, etapas e status de panfletagem.
  - Prefetch esses dados na tela de dashboard (com throttle ou revalidacao periodica).
  - Utilize `meta.etag`: ao montar dashboard, envie `If-None-Match` para reaproveitar cache quando nao houver mudancas.
- **Filtros auxiliares** (`/filtros/*`):
  - Sao leves; porem mantenha cache local (expirar manualmente em 24h ou apos sincronizacao).
  - Apoiam combos/auto complete no app; amortizam latencia com pre-carregamento ao iniciar o app.

### KPIs
- `/kpis/overview`: retorno inclui `total_escolas`, `total_panfletagem_feita`, `total_panfletagem_pendente`, breakdown por etapas (`Etapas`), distribuicao por turno (`turnos`) e bairros criticos (`bairros_top`). Ideal para cards de dashboard.
- `/kpis/historico`: serie diaria (limit 180). Renderize graficos de tendencia; mantenha cache por filtro (cidade/bairro) e atualize sob demanda.

### Sincronizacao Offline
#### Estrategia Recomendada
1. **Captura de mudancas**: ao concluir sincronizacao inicial (fetch completo), armazene `next_since` retornado por `/sync/changes`. Use-o como cursor incremental.
2. **Modo offline**:
   - Operacoes locais (ex.: marcar panfletagem, editar observacao rapida) entram em fila local com payload conforme `POST /sync/mutations`. Guarde tambem `client_mutation_id` para conciliacao.
   - Ao reconectar, envie lote unico respeitando ordem de criacao. O backend responde com `mutations[]` contendo status pendente/aplicado e retorna `escola_id` (quando informado). Caso haja rejeicao, sinalize ao usuario.
3. **Atualizacao incremental**:
   - Apos aplicar mutacoes, consulte `/sync/changes?since=<cursor>` (ou `sinceVersion` para clientes legados). Atualize registros locais conforme payload (`cidades`, `bairros`, `escolas`). Cada escola traz `periodos` e `etapas`, facilitando merge.
   - Atualize o cursor com `next_since` e persista.
4. **Conflitos**: se a mutacao gerar conflito, backend retornara 409; registre estado atual e peca acao manual do usuario.

#### Boas Praticas
- Padronize `client_id` (ex.: ID do dispositivo) e `client_mutation_id` (UUID) para rastrear cada mutacao no app.
- Limite `limit` em `/sync/changes` conforme capacidade do app (ex.: 100). Se `next_since` avancar, repita ate esgotar backlog.
- Persistencia local: utilize storage com versionamento (`versao_row`) para saber se recurso esta defasado antes de enviar mutacao.

### Performance Frontend
- Use `per_page` adaptativo (ex.: 20 em listas, 50 em tablets) e carregamento incremental ao rolar (infinite scroll).
- Armazene respostas criticas em cache local (IndexedDB/SQLite) identificadas por chave (`cidadeId|filters`). Atualize somente quando `updated_at` mudar ou apos sync incremental.
- Debounce buscas (`search`) para evitar requisicoes a cada tecla (ex.: delay de 300-400ms).
- Prefetch detalhes de escola quando usuario estiver a 1 item de rolar (melhora tempo de abertura de tela).

---

## Modulo de Agenda (Eventos)

### Endpoints Disponiveis
| Verbo | Rota | Uso |
|-------|------|-----|
| GET | `/eventos` | Lista paginada de eventos. |
| POST | `/eventos` | Cria evento (para usuarios autorizados). |
| GET | `/eventos/{id}` | Detalhe do evento. |
| PATCH | `/eventos/{id}` | Atualiza campos do evento. |
| DELETE | `/eventos/{id}` | Remove evento. |
| GET | `/eventos/{id}/logs` | Historico paginado de logs (alteracoes). |

### Listagem (`GET /eventos`)
- Query params suportados:
  - `page`, `per_page` (padrao 20).
  - `cidade`: filtra por cidade (texto completo ou sigla, conforme seed disponivel).
  - `search`: titulo/local descricoes.
- Resposta inclui `meta.total`, `meta.page`, `meta.per_page` e header `X-Total-Count` - use para UI de paginacao.
- Estrutura de cada item (`EventResource`):
  ```json
  {
    "id": 12,
    "titulo": "Aula inaugural",
    "descricao": "Treinamento inicial para promotores",
    "cidade": "Tijucas",
    "local": "Auditorio central",
    "inicio": "2025-11-10T08:00:00Z",
    "fim": "2025-11-10T12:00:00Z",
    "criado_por": 3,
    "atualizado_por": 5,
    "criado_em": "2025-10-15T13:42:00Z",
    "atualizado_em": "2025-10-20T09:10:00Z"
  }
  ```
- Melhoria: mantenha cache por mes. Ao abrir agenda de novembro, primeiro leia dados locais; em paralelo, dispare fetch com filtros (`?cidade=...&page=1`) para revalidar.

### CRUD (POST/PATCH/DELETE)
- **Criacao (`POST`)** requer payload com:
  ```json
  {
    "titulo": "...",
    "descricao": "...",
    "cidade": "Tijucas",
    "local": "Rua X",
    "inicio": "2025-11-10T08:00:00Z",
    "fim": "2025-11-10T12:00:00Z"
  }
  ```
- **Atualizacao (`PATCH`)** aceita campos parciais; mantenha validacoes no app (ex.: `fim` >= `inicio`).
- **Remocao (`DELETE`)** retorna 204; remova item localmente e registre acao em log caso precise sincronizar com outros dispositivos.
- Controle offline:
  - Crie fila local (semelhante a mutations) contendo acoes `create/update/delete` com payload completo.
  - Ao reconectar, sincronize sequencialmente. Se backend nao puder reutilizar `/sync/mutations`, crie job dedicado ou priorize eventos somente online (avaliar com produto).

### Logs (`GET /eventos/{id}/logs`)
- Recomendado para telas de auditoria. Resposta inclui `meta` paginado. Cada log costuma trazer status, mensagem e `criado_em`.
- Carregue on-demand quando usuario abrir modal de historico.

### Boas Praticas de UX/Performance
- Aggressive caching em listas: agrupe eventos por data para renderizacao eficiente; evite recalcular grouping a cada frame.
- Pre-carregue detalhes (`GET /eventos/{id}`) quando usuario selecionar item (ou via background request se largura de banda permitir).
- Gere memorias para auto complete de cidades/locais com base na lista de eventos; atualize a cada sync.
- Diferencie eventos passados e futuros para ordenar localmente depois da primeira fetch.

---

## Estrategias Offline Integradas (Escolas + Agenda)
1. **Camada de persistencia local** (SQLite ou IndexedDB):
   - Tabelas: `schools`, `school_observations`, `school_logs`, `events`, `event_logs`, `sync_mutations`.
   - Mantenha `versao_row` (escolas) e timestamps (`atualizado_em`) para saber se dado esta fresco.
2. **Fila de mutacoes**: unifique acoes offline (ex.: `escola.update`, `observacao.create`, `evento.create`). Cada item deve conter:
   - `entity`, `operation`, `payload`, `versao_row`, `client_mutation_id`, `created_at`.
   - Replique logica de `POST /sync/mutations` para escolas; para agenda, avalie endpoint dedicado ou processamento sequencial.
3. **Recuperacao incremental**:
   - Apos aplicar fila, execute `/sync/changes` (escolas) e recarregue `/eventos` em lotes menores (ex.: `per_page=20`, `cidade=<ultima cidade>`, `page` incremental). Marque horario da ultima atualizacao por filtro.
4. **Resiliencia**:
   - Se requisicao falhar (ex.: sem rede), refile item para proxima tentativa e mostre badge informando dados desatualizados.
   - Priorize sincronizacao de mutacoes antes de recarregar listas para evitar sobreposicao de dados antigos.

---

## Checklist para o Time de Frontend
- [ ] Implementar camada de API com suporte a `X-API-Key`, `If-Match`, `If-None-Match` e parsing de envelopes.
- [ ] Centralizar tratamento de `409` (recarregar estado e exibir modal de conflito).
- [ ] Criar servicos de cache para agregadores, filtros e listas de eventos (expiracao configuravel).
- [ ] Manter store offline com cursor (`next_since`) para sincronizacao incremental de escolas.
- [ ] Implementar fila de mutacoes com retry e controle de status (pendente/aplicado/falhou).
- [ ] Adicionar telemetria local: registrar `trace_id` e payload em caso de falha para suporte.
- [ ] Otimizar busca com debounce e carregar dados em background para telas criticas (dashboard, agenda semanal).

---
Manter este arquivo sempre alinhado a especificacao (`docs/openapi/schools.yaml` e futuros OpenAPI de agenda). Qualquer mudanca em contrato (novos filtros, campos ou codigos de resposta) deve ser refletida aqui e comunicada ao time de frontend.
