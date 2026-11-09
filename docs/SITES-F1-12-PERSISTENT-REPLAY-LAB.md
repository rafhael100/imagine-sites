# SITES-F1-12 — Proteção durável de nonce HMAC em PostgreSQL descartável

**Objetivo:** validar atomicidade/uniqueness do futuro replay fence S2S CreateProject v1 com PostgreSQL 18 real, mas banco **efêmero no GitHub Actions**, com papéis falsos e sem chave de produção.

Esquema experimental `auth_replay.nonce_consumptions`: chave primária `(issuer,nonce)`, validade máxima 120s, `consumed_at`, sem payload ou PII. A conta de laboratório `sites_auth_runtime` é NOLOGIN, NOSUPERUSER, NOBYPASSRLS e tem somente INSERT, sem direito de listar, alterar ou apagar nonces. A RLS é `FORCE`.

O CI verifica primeiro consumo afetando uma linha, repetição produzindo zero linhas, issuer inválido, TTL excessivo, expiração, operações de SELECT/UPDATE/DELETE negadas e duas sessões PostgreSQL concorrentes competindo pelo mesmo nonce: a unicidade impede duplicar registro.

**Limites:** o código de verificação PHP F1-11 ainda usa `DenyingReplayStore` por padrão e não chama este banco. O laboratório prova propriedade do schema sob teste, mas não constitui serviço operacional de nonce. Em produção, o adaptador transacional deve garantir consumo atômico só depois da verificação criptográfica e temporal, sem allow se banco indisponível, com rotação de chaves, auditoria, retenção segura dos registros e proteção a replay em todos os workers/regiões. Aplicar papel mínimo sem `SET ROLE` do superuser, transport security e idempotência de negócio separada.

**Gates:** P0-DOC03/06, RF-PEND-01/RF-PEND-04, RV-CT, LGPD e revisão AppSec. Sem merges/deploy, dados de cliente, secrets ou despesas. AGI-11-RB01 desabilitado.
