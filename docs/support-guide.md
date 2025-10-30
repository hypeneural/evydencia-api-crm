# Guia Rápido de Suporte — Escola Connect API

## 1. Canais e Escalação
- **N1 (Primeiro atendimento)**: Promotores / Suporte de Campo (`suporte@evy.com`, WhatsApp corporativo).
- **N2 (Aplicação/API)**: Squad Escola Connect (`#escola-connect-api`, @dev-escola).
- **N3 (Infraestrutura)**: SRE / DevOps (`#infra-on-call`, @infra).
- Escalação crítica: telefone de plantão + e-mail `oncall@evy.com`.

## 2. Checklists de Diagnóstico
### 2.1 Falha em Atualizar Panfletagem
1. Coletar `Trace-Id` (header na resposta).
2. Verificar conflito 409 (If-Match). Instruir usuário a sincronizar novamente.
3. Consultar `/metrics` → `http_requests_total{route="/v1/escolas/:id"}` e `http_request_duration_seconds`.
4. Revisar logs (`var/logs/app.log`) procurando pelo `Trace-Id`.

### 2.2 Listagem de Cidades Lenta
1. Verificar histograma `http_request_duration_seconds{route="/v1/cidades"}`.
2. Validar cache: checar hits 304 (`http_requests_total{status="304"}`).
3. Avaliar Redis (`cache:aggregates:*`). Limpar cache se necessário (`php ./scripts/cache-clear-aggregates.php`).

### 2.3 Rate Limit (429)
1. Confirmar cabeçalhos `X-RateLimit-*` retornados.
2. Identificar IP no log (`Rate limit exceeded`).
3. Orientar usuário a aguardar reset (`X-RateLimit-Reset`).
4. Se incidente generalizado, ajustar `RATE_LIMIT_PER_MINUTE` temporariamente.

## 3. Referências
- **Runbook de Deploy**: `docs/deploy-runbook.md`
- **Estratégia de Cache**: `docs/cache-strategy.md`
- **Checklist de Observabilidade**: `docs/escola-module-todo.md` (Etapa 5)

## 4. Snippets Úteis
```bash
# Consultar métricas (autenticação por bearer token)
curl -H "Authorization: Bearer $METRICS_AUTH_TOKEN" http://api.local/metrics

# Verificar uso de rate limit no Redis (exemplo)
redis-cli --scan --pattern "rate_limit:*" | head

# Tail de logs com filtro por Trace-Id
rg "Trace-Id" var/logs/app.log
```

## 5. SLA e Comunicação
- **Incidentes críticos (P0)**: resposta ≤ 30 min, atualização a cada 30 min.
- **Incidentes altos (P1)**: resposta ≤ 1h, atualização a cada 60 min.
- Avisos a usuários finais via canal de broadcast com mensagem padronizada (template no Confluence).

_Bom suporte começa com captura do Trace-Id e registro no ticket!_
