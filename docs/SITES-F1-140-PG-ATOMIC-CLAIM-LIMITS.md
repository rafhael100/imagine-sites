# F1-140 — Claim técnico com vínculo bloqueado na MESMA transação (PostgreSQL 18)

## Objetivo
A F1-139 comparava duas leituras de um snapshot em memória, sem garantia de atomicidade. Este laboratório F1-140 usa PostgreSQL 18 descartável e duas sessões PDO para demonstrar que `SELECT ... FOR SHARE` da revisão do vínculo, seguido de `INSERT ... ON CONFLICT DO NOTHING` da alegação técnica, ocorrem sob a **mesma transação de banco**, impedindo UPDATE/DELETE concorrente do vínculo antes de `COMMIT`/`ROLLBACK`.

## Arquivos
- `labs/postgres/f1-140-atomic-binding-claim.sql`: banco CI `sites_f140_atomic_lab`, role restrita `sites_f140_app`, tabela de versões de vínculo (somente administrador da fixture escreve), tabela de alegações técnicas e função laboratório `f140_lab.try_claim` SECURITY DEFINER de escopo limitado. A role não possui SELECT/INSERT/UPDATE/DELETE direto nas tabelas. `search_path` fixado.
- `tests/f140-pg-atomic-binding-claim.php`: concorrência real em duas conexões de aplicação, bloqueio do UPDATE de revisão por sessão administradora (SQLSTATE 55P03), replay físico único, alterações de revisão e destino, revogação, vínculo inexistente e contagem de gravações.
- `.github/workflows/sites-f1-140-atomic-binding-claim.yml`: PostgreSQL 18 efêmero, PHP 8.3 e 8.4.

## Diagnóstico **não operacional**
`CLAIM_RECORDED_UNVERIFIED` significa somente que a fixture inseriu uma linha de metadados. `EXISTING_CLAIM_UNVERIFIED` é apenas replay técnico. Nenhum deles representa reserva de lead operacional, aceite de usuário, consentimento, resposta HTTP, recepção no CRM ou ACK. É proibido mapear estes resultados para `delivered`, `accepted` ou aprovação de publicação.

## Limites críticos
1. **Não existe autenticação de tenant.** A role sintética pode chamar a função para UUIDs de qualquer organização: o banco não confia no requerente; esta função NÃO deve ser integrada a um endpoint ou produção. A F1-137 demonstrou que `app.tenant_id` também não autentica um tenant.
2. O bloqueio só sincroniza operações na mesma base e na mesma linha. Não protege mudança originada no Portal/CRM em outra base/serviço, não fornece transação distribuída, identidade mutuamente autenticada, fonte monotônica durável intersistemas nem persistência global de idempotência.
3. `SECURITY DEFINER` é usado exclusivamente na fixture isolada com owner de CI, `search_path` controlado, nomes qualificados, e role sem acesso direto a tabelas. Requer auditoria AppSec/DBA antes de qualquer adaptação.
4. A fixture contém somente UUIDs inventados, sem payload de formulário, pessoas, contatos ou consentimento. A instalação SQL exige o nome de banco CI e role `postgres`, mas isso não é uma proteção criptográfica.
5. O laboratório não elimina janela de corrida após commit ou entre serviços. Para produção, será preciso desenho de autoridade do Portal, IAM, vínculo CRM versionado, prova de recebimento, dados LGPD, auditoria e teste adversarial independente.

**Status:** código e testes exclusivamente em PR draft empilhado sobre F1-139. OP04 (#36) e 10/10 gates de produção seguem BLOCKED. Sem merge em `dev` ou `main`, deploy, tráfego, VPS, Cloudflare, DNS, PII ou novas despesas.
