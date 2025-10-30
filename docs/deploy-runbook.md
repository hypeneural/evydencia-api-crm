# Escola Connect API — Runbook de Deploy

## 1. Pré-Deploy
- **Checklist de readiness**
  - `[ ]` Confirmar branch `main` verdinho (CI ok, cobertura ≥ 85%).
  - `[ ]` Validar que `docs/api.md`, `docs/cache-strategy.md` e `docs/escola-module-todo.md` estão atualizados.
  - `[ ]` Revisar `CHANGELOG.md` e anotar versão planejada (semver).
- **Janela & comunicações**
  - `[ ]` Sincronizar janela com Infra + Produto (evitar campanhas de campo).
  - `[ ]` Programar aviso nos canais #operacoes e #promotores (T -4h).
  - `[ ]` Garantir presença do squad (dev, QA, suporte) no período.
- **Backups & infra**
  - `[ ]` Snapshot do banco (`mysqldump` + storage seguro).
  - `[ ]` Verificar métricas em Prometheus (`/metrics`) e latência base.
  - `[ ]` Limpar filas/cron-sim (se aplicável) para evitar gatilhos durante deploy.

## 2. Deploy
1. **Entrar em modo manutenção (opcional)**
   ```bash
   php artisan down --message="Atualizando Escola Connect API" --retry=60
   ```
   > *Se a aplicação não expõe comando próprio, utilizar reverse-proxy com flag de manutenção.*
2. **Executar migrations**
   ```bash
   php vendor/bin/doctrine-migrations migrate --no-interaction
   ```
   - `[ ]` Confirmar que `sync_mutations` e `eventos` estão consistentes.
3. **Deploy da aplicação**
   - Atualizar artefatos (ex.: `git pull`, `composer install --no-dev`, `php artisan config:cache` se aplicável).
   - Realizar `php vendor/bin/phpunit` no host ou pipeline.
4. **Limpeza de caches**
   - `[ ]` Executar `php ./scripts/cache-clear-aggregates.php` *(TODO: script)*.
   - `[ ]` Limpar caches HTTP/CDN relacionados a `/v1/cidades` e `/v1/escolas`.
5. **Retomar tráfego**
   ```bash
   php artisan up
   ```
   ou remover flag de manutenção no proxy.

## 3. Pós-Deploy Imediato (0–30 min)
- `[ ]` Validar smoke tests automatizados (Postman/Newman ou script `./scripts/smoke.sh`).
- `[ ]` Checar logs de erro (`var/logs/app.log`) e `Trace-Id`.
- `[ ]` Garantir que `/metrics` está respondendo (`curl -H "Authorization: Bearer <token>" http://api/metrics`).
- `[ ]` Verificar dashboards: latência (`http_request_duration_seconds`), erros 5xx, fila de sync.
- `[ ]` Atualizar status nos canais internos (“deploy iniciado/concluído”).

## 4. Monitoramento Estendido (30 min – 24h)
- **Painéis recomendados**
  - `Escolas > Latência (P50/P95/P99)` usando histograma `http_request_duration_seconds`.
  - `Erro rate` com contador `http_requests_total{status=~"5.."}`
  - `Sync Mutations Backlog` (consultar tabela `sync_mutations`).
- **Alertas sugeridos**
  - `Latência P95 > 2s` por 5 minutos.
  - `HTTP 5xx > 1%` por 5 minutos.
  - `Rate Limit` excedido para `/v1/escolas` por mais de 3 requisições consecutivas.
- `[ ]` Registrar incidentes no canal #observabilidade com `Trace-Id`.

## 5. Rollback
1. **Detecção**: se smoke tests falharem ou alertas dispararem continuamente em ≤15 min.
2. **Procedimento**
   - `[ ]]` Recolocar a versão anterior do código (tag/commit anterior).
   - `[ ]` Executar `php vendor/bin/doctrine-migrations migrate prev` (ou script reverso) se migrations quebraram.
   - `[ ]` Restaurar snapshot do banco apenas se dado corrompido (com aval de DBA).
3. **Comunicação**
   - `[ ]` Avisar canais (#operacoes, #promotores) sobre rollback.
   - `[ ]` Abrir incidente no Jira/Statuspage com causa e tempo estimado.

## 6. Pós-Deploy (24h+)
- `[ ]` Atualizar `docs/escola-module-todo.md` e `CHANGELOG.md` com status final.
- `[ ]` Registrar métricas pós-deploy (latência, uso de rate limit, conflitos If-Match).
- `[ ]` Agendar retrospectiva com equipe e consolidar “lessons learned”.

## 7. Checklists de Suporte
- **Contact list**
  - Dev on-call: `@dev-escola`
  - SRE / Infra: `@infra`
  - Produto / Operações: `@produto-escola`
- **Kanban pós-deploy**
  - `[ ]` Tickets em aberto migrados para “Post-Deploy”.
  - `[ ]` Backlog residual anotado no Jira (sprint + epics).

> **Glossário**
> - *Trace-Id*: header retornado pelo `ApiResponder` (inclusive em erros 5xx).
> - *If-Match*: utilizado pelo sync mobile; garantir que o header continue respeitado após deploy.

