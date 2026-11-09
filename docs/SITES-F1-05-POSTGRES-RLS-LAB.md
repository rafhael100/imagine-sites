# SITES-F1-05 — Laboratório PostgreSQL 18 de isolamento por tenant e revogação

**Status:** experimento executável em GitHub Actions com PostgreSQL descartável, sem banco de clientes e sem implantação. PR filho do F1-04, sem merge.

## Objetivo

Verificar diretamente no PostgreSQL, e não apenas no código PHP em memória, as propriedades fundamentais necessárias ao futuro `Imagine Sites Engine`: não visualizar projetos de outras empresas, impedir inserção de pedidos de outro tenant, restringir escrita de releases/publicação, manter unicidade por empresa e impedir acesso após revogação ou suspensão.

## Implementação

- Schema `lab_sites` em banco **exclusivo** `sites_lab`, aprovisionado apenas no contêiner temporário do CI.
- Contas PostgreSQL `sites_lab_tenant_a`, `sites_lab_tenant_b` e `sites_lab_unknown` sem superuser, sem BYPASSRLS, sem DDL e sem credenciais de login.
- RLS `ENABLE` + `FORCE` para tabelas de tenants, permissões, projetos e recibos.
- Verificação de papéis reais via `current_user` e grants vigentes; um ID de tenant no payload ou alteração de GUC **não** comprova autorização.
- Permissões mínimas: consultas restritas e inserção inicial, sem UPDATE/DELETE. Em particular, a conta do runtime não publica nem altera grants.
- Unicidade `(tenant_id, order_ref)` para projetos e `(tenant_id, idempotency_key)` para operações. Isso **não** implementa idempotência completa; provas, conflitos e respostas duráveis exigirão um serviço transacional.
- Asserções abortam o CI se um dado estrangeiro ficar visível, se um insert cruzado entrar, se uma operação duplicar ou se revogação não bloquear consultas e writes.

## Fronteira fundamental: não homologar esta simulação como autorização real

O CI usa `SET ROLE` sob superusuário **somente** para simular diferentes conexões de banco num ambiente efêmero. A futura aplicação não poderá compartilhar superuser, usar a role dona das tabelas, conceder BYPASSRLS nem aceitar que cliente/IA escolha o DB role. Um mapeamento identidade-autorização confiável, tenant, vínculo Portal e revogação precisa ser homologado; nem `SET ROLE` em testes nem `current_user` isoladamente autenticam o usuário do Portal. A estratégia de pools/conexões, mudança de role e eventual função SECURITY DEFINER requer auditoria antes do uso real.

**Não existe** em F1-05: migration Laravel de produção, autenticação S2S, política de pagamento, fila durável, captação redundante, API HTTP, publicação ou backup/restore.

## Como reproduzir somente em contêiner descartável

O workflow `.github/workflows/sites-f1-05-pg-rls-lab.yml` cria `postgres:18` com base `sites_lab` e executa scripts `labs/postgres/f1-05-*.sql` no runner GitHub. Os nomes e dados são ficcionais.

NÃO executar `f1-05-schema.sql` em DB existente ou VPS. A proteção inicial recusa DB diferente de `sites_lab`, mas isso não substitui isolamento real nem protege contra operação deliberada com permissões elevadas.

## Gatilhos de promoção

Apenas depois dos gates P0/RF-PEND: revisar a política de RLS com modelo real de identidade, criar migrations próprias do Sites com checksum, transações e unique/revocation fences, acrescentar tests de concorrência e restore, validar com segredos não expostos, propor homologação e só então promover.

Nenhum merge/deploy automático, gasto, DNS/Cloudflare ou alteração em CRM/Portal por este recorte. `AGI-11-RB01` rollback automático permanece desabilitado.
