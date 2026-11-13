# F1-79 — Ledger PostgreSQL 18 append-only para decisões editoriais (laboratório)

Esta etapa exercita em **PostgreSQL real descartável** o armazenamento de decisões de revisão interna do pacote sintético F1-76..78. Ela NÃO é migration de produção, não é serviço autenticado e não substitui a revisão independente.

## Escopo implementado

- `labs/security/f1-79-internal-review-ledger.sql`: schema `review_f79` em banco descartável `sites_f79_lab`, duas roles ficcionais sem superuser, políticas RLS por tenant, tabela de decisões com chaves únicas por decisão e versão de workflow, hashes obrigatórios de HTML/bundle/dossiê/decisão, flags imutáveis `synthetic_only=true`, `publishable=false`, `official_client_acceptance=false`, e trigger contra UPDATE/DELETE.
- `src/Persistence/LabInternalReviewDecisionStore.php`: adapter PHP CLI que exige flag de laboratório, conexão PDO PostgreSQL no banco permitido, role restrita correspondente ao tenant, registro estruturado exato e digest canônico, inserção parametrizada que não converte duplicidade/erros SQL em sucesso.
- `tests/f79-pg-internal-review-ledger.php`: dois tenants, inserções independentes, leitura isolada por RLS, rejeição de cross-tenant inclusive via SQL direto, duplicidade de decisão, conflitos de ações da mesma revisão, mutações negadas e contenção concorrente de unicidade sob transações. 
- Workflow isolado com PostgreSQL 18 efêmero, PHP 8.3 e 8.4; dados/roles/senhas **explicitamente fictícios**, sem VPN, Cloudflare ou VPS.

## Limites de segurança e status

As roles no laboratório são atribuídas por `SET ROLE` de uma conexão CI privilegiada, não por IAM de serviço real. O dono de schema/superuser, o domínio de backup/restauração e logs não são protegidos contra todos os administradores; o trigger não certifica prova externa de imutabilidade. Não há assinatura verificável por pessoa, integração Portal, reversão monotônica independente do restore, aprovação oficial do cliente nem entrega. Isso exige arquitetura de confiança, rotinas de backup/restore e homologação real.

A tabela guarda apenas **referências fictícias e fingerprints**, sem PII. Em produção serão necessários migrations e credenciais segregadas, fonte de tenant autoritativa, auditoria, política LGPD e um serviço operacional revisado. Todos os 10 gates permanecem `BLOCKED`; nenhum merge, deploy, cliente, DNS ou custo adicional é autorizado por este laboratório.
