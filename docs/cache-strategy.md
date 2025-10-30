# Escola Connect - Estrategia de Cache e Invalidação

## Visão Geral
- Objetivo: equilibrar latência de leitura nos agregadores de cidades/bairros e consistência com atualizações (sync, panfletagem, observações).
- Pilares:
  1. **ETag determinística** baseada na versão mais recente (`versao_row`) e carimbo `atualizado_em`.
  2. **Cache-Control** adaptado ao canal (mobile vs painel web).
  3. **Invalidacao automática** a cada mutação relevante + ferramentas de invalidação manual.

## Endpoints Cobertos
| Endpoint | ETag | Cache-Control (web) | Cache-Control (mobile) |
|----------|------|---------------------|------------------------|
| `GET /v1/cidades` | `sha1(scope + filtros + max_versao + max_atualizado)` | `private, max-age=30, must-revalidate` | `private, max-age=120, stale-while-revalidate=300` |
| `GET /v1/cidades/{cidadeId}/bairros` | `sha1(scope + filtros + max_versao + max_atualizado)` | `private, max-age=30, must-revalidate` | `private, max-age=120, stale-while-revalidate=300` |

- Mobile identificado por `X-Client-Type: mobile` ou `client=mobile` na query.
- Painel web (default) privilegia consistência (TTL curto + `must-revalidate`).
- Mobile aceita `stale-while-revalidate` para reduzir miss em deslocamentos offline.

## Como o ETag é Calculado
1. Coletar o maior `versao_row` e `atualizado_em` das entidades retornadas.
2. Incluir dados aninhados quando `includeBairros=true` (cidades) ou `withEscolas=true` (bairros).
3. Gerar `sha1(json_encode(scope, filtros normalizados, maxVersao, maxAtualizado))`.
4. Responder 304 quando `If-None-Match` coincidir com o novo ETag.

### Impacto de Mutacoes
- Qualquer atualização em escolas (panfletagem, observação, sync mutation) incrementa `versao_row`, invalidando automaticamente o ETag.
- Criação/edição de bairros ou cidades altera `updated_at` via migrations, refletindo no hash.
- Seeds ou scripts de carga devem executar `DELETE FROM cache:escola:*` em Redis, quando adotado, para garantir revalidação.

## Estratégia de Invalidação
1. **Automática (padrão)**  
   - Mutacoes da API disparam incremento em `versao_row` / `atualizado_em` resultando em novo ETag.
   - Clientes enviam `If-None-Match`; servidor responde 304 quando não houver alterações.

2. **Manual (opcional)**  
   - Endpoints administrativos podem limpar chaves `cache:aggregates:cidades` e `cache:aggregates:bairros:{cidadeId}` se for necessário invalidar antes do TTL expirar.
   - Scripts de deploy/hotfix devem chamar rotina `php ./scripts/cache-clear-aggregates.php` (a definir) após alterações massivas via import.

3. **Eventos de Migração/Seed**  
   - Executar migrations com aplicação em modo `maintenance` (API somente leitura).
   - Após migração, limpar caches em Redis e acionar job `sync:changes` para garantir que clientes offline recebam as atualizações.

## Sequência Recomendada para Atualização de Dados
1. Aplicar mutacao (PATCH/POST) → servidor incrementa `versao_row`.
2. Cliente sincrono:
   - Requisita novamente `/v1/cidades` ou `/v1/cidades/{id}/bairros` incluindo `If-None-Match`.
   - Recebe 200 com novo ETag ou 304 (sem mudança).
3. Clientes offline:
   - Após reconexão, enviam `sync/mutations` → servidor processa e devolve `versao_row` atualizado.
   - Realizam `GET /sync/changes` + `GET /v1/cidades` com `If-None-Match` para revalidar caches locais.

## Ações Futuras
- Monitorar taxa de HIT/304 nos agregadores via Prometheus (métrica `escola_connect_cache_hits_total`).
- Avaliar cache distribuído (Redis) com replicação para cidades de alto volume.
- Revisar estratégia de TTL quando UI mobile suportar pré-carregamento assíncrono.


## Variaveis de Ambiente
- `METRICS_ENABLED=true` ativa coleta; combine com `METRICS_AUTH_TOKEN` para proteger `/metrics`.
- `METRICS_ADAPTER` (`redis` ou `in-memory`) e `METRICS_REDIS_PREFIX` definem o backend utilizado pelo Prometheus client.
- `METRICS_HTTP_BUCKETS` permite customizar os buckets do histograma de latencia (ex.: `0.05,0.1,0.25,0.5,1,2,5`).
- `RATE_LIMIT_PATHS` (default `/v1/escolas`) controla os endpoints sujeitos ao guard de rate limiting.
