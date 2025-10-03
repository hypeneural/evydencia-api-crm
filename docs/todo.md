# Evydencia API – Integração com CRM

## To-do
- [ ] Revisar `QueryMapper` para garantir cobertura de todos os filtros `order[*]`, `customer[*]`, `product[*]` descritos na coleção do CRM (inclusive documentos e telefones).
- [ ] Normalizar payloads de agendamento de campanha (corrigir `product.reference`, validar `start_at`/`finish_at` em UTC e aceitar contatos com cabeçalho personalizado).
- [ ] Atualizar documentação de relatórios e campanhas com lista de parâmetros suportados, formatos de exportação (CSV/JSON/NDJSON) e exemplos.
- [ ] Revisitar collection Postman local para refletir as rotas `/v1/reports`, novos filtros e cabeçalhos (`X-API-Key`).
