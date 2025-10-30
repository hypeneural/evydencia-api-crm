# Guia de Consumo Frontend - CRUD de Escolas e Eventos

Este documento detalha como o frontend deve consumir os endpoints de CRUD do módulo de escolas e da agenda de eventos. Todos os exemplos usam o prefixo `/api/v1` e assumem que o header `X-API-Key` está presente.

---

## Convenções Gerais
- **Envelope**: respostas seguem `{ success, data, meta, links, trace_id }`. Erros retornam `success=false` com `error { code, message, errors? }`.
- **Headers úteis**:
  - `If-Match`: requerido nas atualizações de escolas (controle otimista via `versao_row`).
  - `If-None-Match`: pode ser usado para cache condicional em listagens.
  - `X-Request-Id`/`Trace-Id`: capture no app para facilitar debug.
- **Códigos de status**: `200/201` sucesso, `204` (delete sem corpo), `404` não encontrado, `409` conflito de versão, `422` payload inválido, `500` erros genéricos.
- **Recomendações gerais**:
  - Centralize chamadas em um serviço REST que injete headers padrão, trate envelopes e traduza erros em mensagens amigáveis.
  - Faça debounce nas buscas e evite refetch completo quando apenas um registro foi alterado (prefira atualizar o cache local).
  - Em modo offline, use fila de mutações (ver guia `frontend-schools-agenda.md`).

---

## Escolas

### Listar escolas
`GET /v1/escolas`
- **Parâmetros**:
  - `page`, `per_page` (1..100), `fetch=all` (apenas para usuários internos).
  - `search` (nome/diretor/endereço), `filter[cidade_id][]`, `filter[bairro_id][]`, `filter[tipo][]`, `filter[status]` (`pendente|feito|todos`), `filter[periodos][]`, `sort`.
- **Resposta**: `data[]` com objetos `SchoolSummary` (campos principais + `periodos`, `indicadores`, `versao_row`).
- **Boas práticas**:
  - Use paginação incremental (infinite scroll). Armazene `meta.total`, `links.next`.
  - Cache por combinação de filtros para evitar chamadas redundantes (liberar com TTL ou após sync).

### Detalhar escola
`GET /v1/escolas/{id}`
- Retorna `SchoolDetail` (resumo + `etapas`).
- Carregue quando usuário abrir o detalhe, ou faça prefetch dos próximos itens na lista para UX mais fluido.

### Atualizar escola
`PATCH /v1/escolas/{id}`
- **Cabeçalho obrigatório**: `If-Match: <versao_row atual>`.
- **Body** inclui `versao_row` e campos alterados (`panfletagem`, `obs`, `periodos`, `indicadores`, `total_alunos`, `diretor`, `endereco` etc.).
- **Conflito (409)**: acontece quando `versao_row` diverge. Reaja recarregando a escola (`GET`) e apresente telas de merge se necessário.
- **Offline**: enfileire a mutação mantendo `versao_row` original; ao reconectar use `POST /v1/sync/mutations`.

### Observações
| Ação | Endpoint | Observações |
|------|----------|-------------|
| Listar | `GET /v1/escolas/{id}/observacoes` | Paginação padrão; use em modais de histórico. |
| Criar | `POST /v1/escolas/{id}/observacoes` | Body `{ "observacao": "texto" }`. |
| Atualizar | `PUT /v1/escolas/{id}/observacoes/{obsId}` | Body igual ao POST. |
| Excluir | `DELETE /v1/escolas/{id}/observacoes/{obsId}` | Retorna 204. |

- **Boas práticas**:
  - Exiba spinner enquanto aguarda resposta; otimistic update opcional (salvar local antes do 200).
  - Em offline, trate “pendente de envio” marcando observações com status local até sincronizar.

### Logs de panfletagem
`GET /v1/escolas/{id}/panfletagem/logs`
- Útil para auditoria. Carregue somente sob demanda (evitar chamadas em massa).

---

## Eventos (Agenda)

### Listar eventos
`GET /v1/eventos`
- Parâmetros: `page`, `per_page`, `cidade`, `search`.
- Resposta: `data[]` com `EventResource` (título, descrição, cidade, local, `inicio`, `fim`, auditoria).
- Boas práticas:
  - Agrupe por data localmente para UX.
  - Cache por mês ou por filtro e invalide via TTL ou após mutações.

### Criar evento
`POST /v1/eventos`
- Body exemplo:
  ```json
  {
    "titulo": "Formacao de promotores",
    "descricao": "Treinamento inicial",
    "cidade": "Tijucas",
    "local": "Auditório central",
    "inicio": "2025-11-10T08:00:00Z",
    "fim": "2025-11-10T12:00:00Z"
  }
  ```
- Retorno 201 com objeto criado. Valide client-side (`fim` >= `inicio`, campos obrigatórios).
- Offline: se o fluxo exigir, registre mutação local e sincronize sequencialmente (não há endpoint de mutations para eventos, então planeje fila de requisições a enviar quando a rede voltar).

### Detalhar evento
`GET /v1/eventos/{id}`
- Carregue ao abrir tela de detalhe ou utilize dados da listagem (se já completos).

### Atualizar evento
`PATCH /v1/eventos/{id}`
- Body aceita campos parciais (mesmos atributos do POST). Evite enviar campos não alterados.
- Atualize caches e/ou refaça a listagem do mês corrente após sucesso.

### Remover evento
`DELETE /v1/eventos/{id}`
- Retorna 204. Remova o item das listas locais e mantenha histórico para desfazer, se necessário.

### Logs de evento
`GET /v1/eventos/{id}/logs`
- Resposta paginada com alterações (operador, timestamps). Prefetch opcional quando o usuário abrir seções de auditoria.

---

## Fluxos e Boas Práticas Unificadas
- **Camada de serviço**: crie funções `listSchools`, `getSchool`, `updateSchool`, `listEvents`, `createEvent`, etc., isolando endpoints e mapeando envelopes para objetos usados no estado global.
- **Gerenciamento de estado**: sincronize listas e detalhes usando stores reativos (Redux, Zustand, Vuex...) evitando duplicação de requests.
- **Controle de erros**:
  - 422: exiba mensagens de validação específicas (`error.errors`).
  - 409: notifique sobre atualização concorrente e ofereça ação de recarregar/merge.
  - 500: implemente fallback (retry com backoff) e avise o usuário.
- **Offline-first**:
  - Escolas já possuem endpoints de sync; integre com fila de mutações (`POST /v1/sync/mutations` + `GET /v1/sync/changes`).
  - Eventos: caso necessário, armazene requisições pendentes em storage local e envie na ordem quando a rede voltar.
- **Perfis de usuário**: valide permissões antes de exibir botões de criar/editar/deletar (API deve rejeitar quando usuário não autorizado).
- **Telemetria**: capture `trace_id` e `X-Request-Id` para logs do app; útil em chamados de suporte ou quando precisar correlacionar com logs do backend.

---

## Checklist rápido para o Front
- [ ] Implementar serviço REST com headers globais e parsing do envelope.
- [ ] Tratar `If-Match` e `versao_row` no PATCH de escolas.
- [ ] Configurar fila para mutações offline e aplicar política de retry.
- [ ] Cachear listagens com chave de filtro e invalidar após mutações.
- [ ] Preparar mensagens específicas para 422/409/404.
- [ ] Acompanhar `trace_id` nos logs do app para suporte.
- [ ] Garantir testes de integração (mock) cobrindo fluxos de criação/edição/exclusão em ambos os módulos.

Mantenha este documento alinhado a qualquer alteração de contrato nos endpoints. Consulte também `docs/openapi/schools.yaml` e o guia geral `docs/frontend-schools-agenda.md` para detalhes adicionais de filtros e sincronização.
