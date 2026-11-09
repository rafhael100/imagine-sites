# F1-32 — Histórico sintético imutável de revisões no PostgreSQL descartável

## Escopo da prova

O fluxo F1-28 continua puramente em memória. Este recorte **não o conecta a um banco operacional**: acrescenta um laboratório SQL separado, com histórico append-only de eventos de produção e revisão, sem payload pessoal, sem texto de cliente e sem acesso à VPS.

O laboratório utiliza exclusivamente `sites_lab`, `postgres` de CI e identidades SQL fictícias já criadas em F1-05. As linhas referenciam `lab_sites.site_projects` e herdam RLS. O runtime recebe apenas `SELECT/INSERT`; não tem `UPDATE/DELETE`.

## Regras testadas

1. `draft_started → draft_submitted → internal_approved` exige revisões estritamente crescentes, digest SHA-256 exato e tentativa de produção coerente.
2. `portal_review_returned` e `internal_rework_requested` removem digest anterior e incrementam a tentativa, limitada a 3 neste laboratório.
3. O relógio do PostgreSQL define `recorded_at`; timestamps de remetentes não são autoritativos.
4. Eventos duplicados ou concorrentes com a mesma revisão conflitam pela chave primária. Clientes sem papel SQL de escrita ficam recusados via RLS/ACL; tenant B não enxerga registros A; revogação experimental retira a visibilidade.
5. Campos `actor_role` são somente rótulos sintéticos para as transições: **não são autenticação**. O código não confere o titular do contrato nem a função real do agente/editor.

## Limites

É um **ensaio isolado** no PostgreSQL 18 efêmero do GitHub CI. O workflow não instala migrations Laravel, não habilita serviço público, não implementa outbox de verdade para estas revisões e não fornece proteção completa a corrida concorrente em múltiplos nós. Unicidade por revisão rejeita gravações simultâneas com a mesma chave, mas é necessário projetar transações e controle de versão de longo prazo, imunidade a restauração de backup, autorização oficial de atores, assinatura e auditoria contínua.

O Portal segue responsável pelo aceite expresso e por `ENTREGUE`. Nenhum evento do laboratório comprova publicação, homologação do cliente, aprovação de IA ou capacidade de go-live. `config/operational-readiness.json` permanece BLOCKED; P0/OP05/RF-PEND, AppSec/LGPD abertos; AGI-11-RB01 desabilitado.

**Para executar:** workflow `sites-f1-32-pg-workflow-ledger.yml` (Postgres 18 descartável em PR). Nenhum SSH, API, CDN, Cloudflare, DNS ou custo de serviço foi ativado.
