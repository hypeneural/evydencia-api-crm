# Evydencia API - Integracao com CRM

## To-do
- [x] Revisar `QueryMapper` para garantir cobertura de todos os filtros `order[*]`, `customer[*]`, `product[*]` descritos na colecao do CRM (inclusive documentos e telefones).
- [x] Normalizar payloads de agendamento de campanha (corrigir `product.reference`, validar `start_at`/`finish_at` em UTC e aceitar contatos com cabecalho personalizado).
- [x] Atualizar documentacao de relatorios e campanhas com lista de parametros suportados, formatos de exportacao (CSV/JSON/NDJSON) e exemplos.
- [ ] Revisitar collection Postman local para refletir as rotas `/v1/reports`, novos filtros e cabecalhos (`X-API-Key`).
