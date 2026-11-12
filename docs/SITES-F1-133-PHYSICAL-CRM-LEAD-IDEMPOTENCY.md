# F1-133 — Prova física PostgreSQL 18 de identidade idempotente de leads (sem conteúdo)

## Lacuna depois da F1-132

A F1-132 mostrou que o Sites e o CRM compartilham o mesmo escopo lógico sintético de deduplicação, **organização + submissão**, e que a classe de CAS do CRM NÃO reserva nada nem confirma entrega. O CRM já tinha uma prova física F3-13 de concorrência na tabela assíncrona experimental, que não armazena o site, formulário ou destino original CRM. A F1-133 testa o próximo requisito físico: imutabilidade do **contexto originalmente associado à submissão** sob disputas reais pelo índice único, ainda sem uma API.

## Implementação exclusivamente descartável

O novo esquema `labs/postgres/f1-133-lead-metadata-ledger.sql` só pode ser executado pelo usuário administrador `postgres` no banco **efêmero** `sites_f133_lead_lab`. Não é migração operacional. A tabela `f133_lead.synthetic_metadata_claims` guarda somente seis UUIDs sintéticos: organização, submissão, vínculo, site, formulário e destino original, mais `created_at` **gerado pelo servidor** e estado fixo `registered_for_test`. **Não guarda nome, telefone, e-mail, contato, payload, consentimento, token, ACK ou status de entrega.**

- `PRIMARY KEY (organization_reference, submission_reference)` serializa a identidade lógica entre conexões.
- O login restrito `sites_f133_lead_app` recebe apenas `SELECT` e `INSERT` nas seis colunas de metadados. Não possui `UPDATE`, `DELETE`, `TRUNCATE` nem escrita em `created_at`/estado.
- `ENABLE/FORCE ROW LEVEL SECURITY` e políticas SELECT/INSERT verificam `app.tenant_id` **dentro da transação**. Essa GUC **não autentica o tenant**, e por isso não poderá ser usada como prova de identidade num serviço operacional.
- `ON CONFLICT DO NOTHING RETURNING` decide a inserção física; o chamador deve consultar o primeiro registro e **recusar o contexto divergente**, nunca realocar um lead para outra conta CRM.
- O timestamp original é determinado pelo banco e não pode ser fornecido ou reiniciado pelo login de laboratório.

## Teste real de concorrência e rollback

O workflow `sites-f1-133-pg-lead-metadata.yml` cria PostgreSQL 18 efêmero no GitHub Actions e executa `tests/f133-pg-lead-idempotency.php` sob PHP 8.3 e 8.4, sem Laravel, Composer, serviço externo ou outras contas:

1. Duas conexões PDO físicas competem pela mesma chave quando o primeiro INSERT ainda não foi confirmado: a segunda recebe timeout de lock SQLSTATE **55P03**. O lock não é um sinal de entrega, e a aplicação não faz retry cego.
2. Depois de COMMIT, a mesma submissão faz **0 inserções adicionais**. Se tentar alterar vínculo/site/formulário/destino CRM original, a unicidade não é contornada e o primeiro destino continua imutável.
3. A role restrita não pode modificar registro nem forjar o relógio T0; verificam-se suas permissões e RLS obrigatória.
4. INSERT cross-tenant com contexto incompatível é recusado (SQLSTATE **42501**). A mesma submissão em empresas diferentes é isolada pelo escopo tenant + UUID e pode ter linhas independentes.
5. Duas submissões distintas na mesma empresa permanecem independentes: não existe deduplicação por pessoa.
6. Rollback de um primeiro registro abre espaço para exatamente uma inserção posterior. Nenhuma sessão retém a GUC transacional depois de COMMIT.
7. O contrato Sites `SyntheticCrmLeadLedgerIntentV1` continua explicitamente **DENY** para confirmação ao visitante, reserva operacional ou entrega ao CRM.

## Riscos e dependências operacionais ainda não resolvidos

Esta prova NÃO estabelece um ledger de leads operacional nem uma transação CAS segura de ponta a ponta. O contexto de tenant é fornecido pela fixture de teste, não por identidade autenticada. O banco CI é único e descartável, enquanto a arquitetura prevista requer **dois ingressos fisicamente independentes** com decisões consistentes sob falhas e partições. Não existe confirmação CRM, outbox operacional, chave/segredo gerenciado, autorização atual de vínculo, registro T0 governado e auditado, retenção LGPD, criptografia de PII ou processo de eliminação após 18 dias. Uma resposta HTTP 201 não equivale a comprovar recebimento ou persistência.

A OP04 (#36) e os dez gates de produção continuam ABERTOS/BLOCKED, inclusive revisão SEG/DBA/AppSec independente. O trabalho está em PR draft isolado; **nenhum** merge dev/main, deploy, VPS, DNS, Cloudflare, cliente, PII ou novo gasto.
