# Escola Connect API - Plano de Entrega

Checklist dividido por entregas incrementais. Marque cada item ao concluir e registre evidencias (commits, testes, links).

## Analise de Andamento Atual
- Etapas 1 a 3 concluidas consolidando migrations, repositorios e actions cobertos por testes repository/service/feature.
- Etapa 4 entregou CRUD de eventos e sincronizacao offline; falta publicar agregadores de cidades/bairros e alinhar cache.
- OpenAPI e validacao de respostas estao ativas; proximos passos focam observabilidade, hardening e preparacao de release.

## TODO Pos-Testes Manuais (2025-10-30)
- [x] Corrigir `app/Actions/Concerns/ResolvesRequestContext.php` para restaurar namespace/metodos (`resolveTraceId`, etc.) e eliminar os 500 nas actions de escolas/sync.
- [x] Reexecutar `php test_endpoints.php` (2025-10-30 06:43 UTC) validando `GET/PATCH/POST` de escolas, observacoes e rotas de sync; unico 409 restante e o conflito esperado no revert final.
- [x] Revisar codificacao das fixtures/dados: criado `database/seeds/20251030_fix_encoding.sql` e confirmado respostas com `CEI Prof. Marco Aurélio` em UTF-8.
- [x] Confirmar contrato de `/v1/sync/mutations`: ação agora aceita `tipo`/`type` e `payload`/`updates` (ver `app/Actions/Schools/EnqueueSchoolSyncMutationsAction.php`); testes retornam 200 com payload documentado.

## Roadmap de Producao (Go-Live)
1. **Agregadores & Cache (Semana 1)**  
   - Finalizar S1-5 a S1-10 (fixtures de borda, ETag/Cache-Control, documentacao de invalidacao).
   - Ensaiar consultas em massa e validar padroes de filtros/ordenacao.
2. **Observabilidade & Hardening (Semana 2)**  
   - Instrumentar metricas Prometheus, ativar rate limiting e revisar logs.
   - Configurar alertas Grafana/Sentry e validar coleta em ambiente homolog.
3. **Homologacao & QA (Semana 3)**  
   - Executar checklist de homologacao com produto, atualizar docs/API e changelog.
   - Rodar smoke tests automatizados e capturar evidencias.
4. **Deploy Controlado (Semana 4)**  
   - Executar runbook de deploy/rollback, migracoes e validacoes pos-deploy.
   - Monitorar dashboards por 24h seguindo plano de suporte e SLA.
5. **Pos-Entrega & Suporte Contínuo**  
   - Comunicar times, conduzir treinamento, consolidar feedback e planejar backlog fase 2.
   - Registrar aprendizados, atualizar indicadores e agenda de revisao trimestral.

## Prioridades Imediatas (Semana 2)
- **Observabilidade**: finalizar alertas (Grafana/Sentry) e planejar dashboards de panfletagem/offline.
- **Hardening**: revisar mascaramento de logs sensíveis e preparar testes de carga para rate limiting.
- **Documentação operacional**: evoluir runbook de deploy/rollback e guias de suporte.
- **QA preparatório**: alinhar checklist de homologação com produto e rascunhar smoke tests automatizados.

## Etapa 1 - Base de Dados e Repositorio
- [x] Criar migrations das tabelas (`cidades`, `bairros`, `escolas`, `escola_periodos`, `escola_etapas`, `escola_observacoes`, `escola_observacao_logs`, `escola_panfletagem_logs`, `sync_mutations`, `eventos`, `evento_logs`, `usuarios` quando necessario)
- [x] Adicionar indices recomendados e comentarios de auditoria
- [x] Popular seeds minimas (cidades/bairros) para testes manuais
- [x] Implementar `SchoolRepositoryInterface` (busca, detalhe, filtros, logs, sync)
- [x] Implementar `PdoSchoolRepository` com paginacao, busca fulltext, filtros compostos
- [x] Testar migrations: `php vendor/bin/doctrine-migrations migrate --dry-run`
- [x] Testar repositorio com suite de integracao: `php vendor/bin/phpunit --testsuite=repository`

## Etapa 2 - Servicos e Mapeamento de Queries
- [x] Criar `SchoolService` centralizando regras de panfletagem, observacoes e KPIs
- [x] Estender `QueryMapper` para aceitar filtros/sorts de escolas
- [x] Validar DTOs/contratos (`Etapas`, `Indicadores`, `SchoolDetail`, `SchoolList`)
- [x] Adicionar testes unitarios de servico (`php vendor/bin/phpunit --testsuite=service`)
- [x] Garantir idempotencia com `versao_row` + `If-Match`

## Etapa 3 - Endpoints e Middlewares
- [x] Criar actions Slim (listar, detalhar, atualizar, observacoes, logs, filtros, KPIs, sync)
- [x] Registrar rotas em `config/routes.php` sob `/v1/escolas`
- [x] Escrever documentacao OpenAPI (annotations nos actions) _(ver `docs/openapi/schools.yaml`)_
- [x] Ajustar `ApiResponder` para incluir `filters` no meta onde aplicavel
- [x] Rodar testes funcionais/API (`php vendor/bin/phpunit --testsuite=feature`)
- [x] Validar respostas via `OpenApiValidationMiddleware` (ativar `validate_responses`)

## Etapa 4 - Eventos e Offline
- [x] Implementar CRUD de eventos (repositorio, servico, actions)
- [x] Registrar logs (`evento_logs`) nas mutacoes
- [x] Incluir tipos `createEvento`, `updateEvento`, `deleteEvento` em `/v1/sync/mutations`
- [x] Cobrir com testes de fila/offline (`php vendor/bin/phpunit --testsuite=sync`)
- [x] Prioridade Semana 1 (finalizar 100% antes de iniciar Etapa 5)
- [x] Expor agregadores `/v1/cidades` e `/v1/cidades/{id}/bairros` com metricas para filtros rapidos
  - [x] Mapear consultas SQL com agregacoes (CTEs/paginacao) cobrindo totais por etapa e panfletagem _(S1-1)_
  - [x] Estender `SchoolRepositoryInterface` com metodos `listCityAggregates` e `listNeighborhoodAggregates` _(S1-2)_
  - [x] Implementar actions `ListCityAggregatesAction` e `ListNeighborhoodAggregatesAction` _(S1-3)_
  - [x] Atualizar OpenAPI com exemplos paginados e parametros `includeBairros`, `withEscolas` _(S1-4)_
- [x] Adicionar testes de integracao cobrindo agregadores e cenarios de borda (cidade sem bairros, filtros combinados, paginacao)
  - [x] Garantir conversao de escolas nos agregadores via testes de servico (`tests/Service/SchoolServiceAggregatesTest.php`)
  - [x] Preparar fixtures com cidades sem bairros, bairros sem escolas e dados incompletos _(S1-5)_ (`tests/Fixtures/AggregatesFixtures.php`)
  - [x] Validar filtros combinados (periodos, status, texto) e ordenacao _(S1-6)_ (`tests/Feature/NeighborhoodAggregatesActionTest.php`)
  - [x] Garantir resposta 304 quando `If-None-Match` coincide com ETag agregada _(S1-7)_ (`tests/Feature/CityAggregatesActionTest.php`)
- [x] Configurar ETag e Cache-Control especificos para agregadores garantindo coerencia com sync
  - [x] Gerar ETag baseado no `versao_row` agregado e `updated_at` mais recente _(S1-8)_ (`app/Application/Services/SchoolService.php`)
  - [x] Ajustar `Cache-Control` diferenciando clientes mobile (stale-while-revalidate) e painel web _(S1-9)_ (`app/Actions/Schools/ListCityAggregatesAction.php`)
  - [x] Documentar fluxos de invalidacao em `docs/cache-strategy.md` _(S1-10)_

## Etapa 5 - Observabilidade e Hardening
  - [x] Instrumentar metricas (tempo de resposta, contagem de toggles) com Monolog/Prometheus
    - [x] Adicionar middleware de temporizacao nas rotas `/v1/escolas/*` e `/v1/cidades/*` (`app/Middleware/MetricsMiddleware.php`)
    - [x] Publicar contadores e histogramas em namespace `escola_connect_api_*` (`app/Infrastructure/Metrics/MetricsService.php`)
    - [x] Validar coleta local com `php vendor/bin/phpunit` (`tests/Middleware/MetricsMiddlewareTest.php`, `tests/Infrastructure/Metrics/MetricsServiceTest.php`)
  - [ ] Expor endpoint `/metrics` autenticado para coleta pelo Prometheus
    - [x] Configurar autenticacao basica/bearer com role `observability` (`app/Actions/Monitoring/GetMetricsAction.php`)
    - [ ] Atualizar `docker-compose` para incluir Prometheus/Scrape config
  - [ ] Configurar alertas de erro e latencia (Grafana/Sentry) com limiares acordados
    - [ ] Definir playbook de escalacao para falhas de sync
    - [ ] Criar dashboards com funis de panfletagem e status offline
  - [x] Adicionar rate limiting especifico para `/v1/escolas/*`
    - [x] Implementar guard baseado em Redis com limites por token (burst/sustained) (`app/Middleware/RateLimitMiddleware.php`, `config/settings.php`)
    - [ ] Cobrir com testes de carga simples (k6 ou artillery)
  - [x] Documentar estrategias de cache (Redis) e invalidacao
    - [x] Descrever chaves, TTLs e regras de invalidacao pos-mutacao (`docs/cache-strategy.md`)
    - [x] Criar quick-reference para equipe de suporte (`docs/cache-strategy.md`)
- [ ] Revisar logs para mascarar campos sensiveis
  - [ ] Auditar formatadores Monolog para remover dados pessoais
  - [ ] Garantir anonimizacao de observacoes em relatorios agregados
- [ ] Executar lint e analise estatica (`composer run-script psalm`, `composer run-script phpcs`)

## Etapa 6 - Entrega e QA
- [ ] Revisao de codigo dupla (PR)
  - [ ] Preparar branch final com commits revisados
  - [ ] Registrar checklist de revisao (seguranca, performance, DX)
- [ ] Alinhar checklist de homologacao com time de produto (casos de panfletagem, sync offline, filtros)
  - [ ] Definir cenarios obrigatorios e dados de teste compartilhados
  - [ ] Agendar sessao de homologacao assistida
- [ ] Atualizar `docs/api.md` com exemplos de respostas padronizadas
  - [ ] Adicionar cenarios de erro (409 If-Match, 304 ETag)
  - [ ] Integrar tabelas de parametros e responses no formato usado pelo mobile
- [ ] Publicar changelog/release notes
  - [ ] Registrar features, fixes e itens de observabilidade
  - [ ] Validar com stakeholders a versao semantica
- [ ] Executar smoke tests pos-deploy
  - [ ] Automatizar script de validacao (bash ou Postman CLI)
  - [ ] Registrar evidencias e logs das primeiras execucoes
- [ ] Monitorar dashboards durante as primeiras 24h
  - [ ] Estabelecer turnos de acompanhamento com time de suporte
  - [ ] Definir metricas chave (erro 5xx, latencia P95, fila de sync)
- [ ] Registrar licoes aprendidas e issues abertas apos monitoramento

## Etapa 7 - Pos-Entrega e Roadmap
- [ ] Planejar ciclo de feedback com equipe de campo (formulario e checkpoints quinzenais)
- [ ] Mapear backlog da fase 2 (integracao BI, automatizacoes de indicadores, export CSV)
- [ ] Formalizar plano de suporte (SLA, playbook de escalacao, contatos)
- [ ] Designar owners por macrotema (sync, observabilidade, BI) e registrar no Confluence
- [ ] Programar revisao trimestral de indicadores e roadmap

## Cronograma Sugerido
  - [x] Semana 1: concluir agregadores de cidades/bairros e publicar documentacao OpenAPI atualizada (S1-1 a S1-10 fechados)
  - [ ] Semana 2: configurar alertas (Grafana/Sentry), revisar logs e preparar testes de carga do rate limiting
- [ ] Semana 3: executar homologacao conjunta, smoke tests e liberar release candidate
- [ ] Semana 4: monitorar deploy, consolidar licoes aprendidas e planejar backlog fase 2

## KPIs e Validacao Continua
- [ ] Definir baseline de latencia (P50, P95) e erros 4xx/5xx para alertas
- [ ] Monitorar sucesso da panfletagem (percentual de escolas concluidas por cidade)
- [ ] Acompanhar taxa de conflitos If-Match e tempo medio de resolucao
- [ ] Coletar feedback de usabilidade mobile a cada sprint

## Plano de Testes & Qualidade
- [ ] Atualizar suite de testes automaticos incluindo cenarios para agregadores
- [ ] Configurar pipeline CI com gates obrigatorios (unit, integration, feature, sync)
- [ ] Criar caderno de testes manuais priorizando fluxos mobile offline
- [ ] Validar cobertura de codigo minima (>= 85%) antes do release candidate
- [ ] Registrar evidencias de testes no repositório (`docs/test-reports/`)

## Plano de Deploy & Rollback
- [ ] Definir janela de deploy com infraestrutura e comunicados aos stakeholders
- [ ] Preparar scripts de migracao reversivel (down) para tabelas recentes
- [ ] Validar playbook de rollback (snapshot banco, feature toggles)
- [ ] Checar capacidade de escalonamento horizontal durante a janela de go-live
  - [x] Documentar passos no `docs/deploy-runbook.md`

## Monitoramento & Suporte
- [ ] Configurar alertas no Slack/Email para latencia, erro e fila de sync
- [ ] Criar painel unificado (Grafana) com visao por cidade, bairro e escola
- [ ] Estabelecer processo de triagem de chamados (N1, N2, N3)
- [ ] Definir SLA de resposta para incidentes criticos (< 30 min)
  - [x] Consolidar contatos de suporte e escalacao no playbook (`docs/support-guide.md`)

## Comunicacao & Treinamento
- [ ] Criar material de onboarding para promotores (PDF + video curto)
- [ ] Agendar treinamento remoto com equipe de campo antes do deploy
- [ ] Preparar FAQ com top 10 duvidas sobre sync offline
- [ ] Disponibilizar canais de feedback (formulario, grupo WhatsApp corporativo)
- [ ] Documentar mensagens padrao de comunicacao para status de incidentes

## Dependencias & Riscos
- [ ] Validar com time de infraestrutura limites de Redis e configuracoes de Prometheus
- [ ] Confirmar disponibilidade da equipe de dados para dashboards em tempo real
- [ ] Planejar janela de deploy para evitar conflito com campanhas em campo
- [ ] Mitigar risco de indisponibilidade de sync durante migracoes (modo read-only temporario)
- [ ] Provisionar capacity extra caso volume de escolas cresca >20% durante campanha

## Documentacao & Artefatos
- [ ] Atualizar `docs/openapi/schools.yaml` com novas rotas de agregadores e exemplos
- [ ] Manter changelog em `docs/CHANGELOG.md`
  - [x] Publicar guia rapido para suporte (`docs/support-guide.md`)
- [ ] Revisar `docs/api.md` garantindo consistencia com OpenAPI
- [ ] Arquivar decisoes de arquitetura no ADR correspondente (ADR-004 Aggregates)

## Checklist de Encerramento
- [ ] Todas as caixas marcadas com [x] revisadas por pelo menos 2 pessoas
- [ ] Evidencias anexadas (links para PRs, testes, dashboards)
- [ ] Stakeholders informados sobre conclusao e proximos passos
- [ ] Backlog residual registrado no Jira (sprint backlog pos-projeto)
- [ ] Retrospectiva agendada com o time completo
