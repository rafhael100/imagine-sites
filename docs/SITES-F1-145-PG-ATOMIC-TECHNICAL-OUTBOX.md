# F1-145 — Outbox técnico atômico em PostgreSQL 18 (somente CI)

A etapa F1-145 acrescenta uma tabela `f140_lab.synthetic_claim_outbox` no banco descartável `sites_f140_atomic_lab`. Quando a função técnica `try_claim` consegue inserir um claim por empresa+submissão, insere também exatamente uma linha de **metadados** de saída na **mesma transação**. Uma falha ou rollback não deixa metade da operação persistida. Replay idêntico retorna `EXISTING_CLAIM_UNVERIFIED` sem duplicar o outbox.

O outbox contém somente identificadores técnicos, revisão fixada, hora gerada no banco e estado imutável `pending_unverified`. Nenhum campo pessoal, consentimento, corpo de formulário, instrução ao worker, URL de entrega ou confirmação CRM foi criado. O estado `delivered` é proibido pelo `CHECK` da fixture.

## Evidências de laboratório

O teste `tests/f145-pg-atomic-technical-outbox.php` executa duas organizações com a mesma submissão UUID: commit atômico, visibilidade apenas após commit, replay sem linha extra, rollback de claim+outbox, retry após rollback, isolamento do login PostgreSQL, negação após revogação e restrição de SELECT pelas roles de aplicação. CI PostgreSQL18/PHP8.3 e PHP8.4.

## Não é produção

Não há autenticação real do Portal, vínculo operacional, ingestão de lead, CRM ACK, transporte/filas de entrega, worker, LGPD, retry/TTL, outbox distribuído ou monitoração. Contas de DB são fictícias, não devem ser usadas por navegadores/clientes/IA. OP04 #36 e 10/10 gates BLOCKED até arquitetura de duas entradas físicas, autorizações, revisão SEG/DBA/AppSec, homologação e consentimento. Sem merge dev/main, VPS, DNS, Cloudflare ou gastos.
