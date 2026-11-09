# SITES-SEC-01 — Matriz de segurança e bloqueios

**Matriz candidata. Não fecha qualquer gate existente.**

| Gate | Evidência antes da liberação | Estado |
| --- | --- | --- |
| P0-DOC01/02 + RF-PEND-01 | Corpo AGI e precedência completos | ABERTO |
| P0-DOC03 | Autoridades e contratos versionados com revogação | ABERTO |
| P0-DOC04 | Release, manifesto, aceite exato e prova de publicação | ABERTO; mock lógico |
| P0-DOC05 + RF-PEND-04 | 2 APIs captadoras independentes, T0, CRM, descarte | ABERTO; só cálculo 432h |
| P0-DOC06 | Isolamento tenant/RLS, RBAC/MFA e autenticação real | ABERTO; só IDs fictícios |
| P0-DOC07/09/12 | Cloudflare, hostnames, SSL, DNS, contratos e limites | ABERTO |
| P0-DOC08 | Backup e restore sem reativar revogação/dados expirados | ABERTO |
| P0-DOC10/11 | Financeiro, briefing, atendimento e direitos | ABERTO |
| P0-DOC13/14 | Escopo final, revisão e homologação versionadas | ABERTO |
| AGI-11-RB01 | Rollback automático | DESABILITADO |

Bloqueios: tenant indevido, token/assinatura não verificável, aceite para digest diferente, provedor 202 sem versão observada, lead para CRM errado, retry após 432h, fila duplicada sem idempotência, restauração de payload expirado, agente com credencial geral, PII/segredos em GitHub público, custo/deploy sem autorização.

**F1-01 não testa API, HTTP, autenticação, database, RLS, cloud, n8n, backups, leis ou CRM.** O ledger em memória não equivale a uma fila durável ou prova de autorização.
