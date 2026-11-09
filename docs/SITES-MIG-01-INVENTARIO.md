# SITES-MIG-01 — Inventário e migração progressiva

| Fonte | Componentes | Destino |
| --- | --- | --- |
| imagine-portal/main | server/briefing.mjs, briefing-ui.js | Regras e jornada do Portal; Sites recebe revisão aprovada |
| imagine-portal/main | server/production.mjs, production-ui.js | Versão, histórico, preview; aceite e ENTREGUE ficam no Portal |
| imagine-portal/main | docs/JORNADA-ONEPAGE.md | Casos de teste funcionais, sem migrar SQL Node/SQLite |
| imagine-portal/branches Laravel | apps/portal UI, auth e demonstrativos | Preservar Portal; adapter futuro em PR separado |
| imagine-portal/main | docs/imagine-sites AUT e P0/RV-CT | Fonte normativa; não confundir com homologação |
| imagine-crm/main | IN-122/124/127/149 | Vínculos, captação, confirmação CRM e T0+432h |
| imagine-ai-agents | AIA-12 | Ferramentas centrais tipadas |
| imagine-performance-ai | PERF BL-03 | Landing sob contrato independente |

## Fases
- F1-01: repo inicial, docs, políticas PHP puras e testes de laboratório (este recorte).
- F1-02: contratos candidatos, adaptadores fail-closed e testes adversariais mockados.
- F1-03: fundação Laravel/PostgreSQL independentes, somente após gates relevantes.
- F1-04: persistência de projetos, manifestos, revisões, filas duráveis e segurança por recurso.
- F1-05: IA sandbox, revisão de artefatos, custos/licenças.
- F1-06: publicação sintética, depois staging autorizado de Cloudflare com prova de versão.
- F1-07: recepção redundante de lead, confirmação CRM, descarte e restore seguro.
- F1-08: homologações, piloto real e escala sob liberação específica.

Não copiar dados/segredos nem alterar o Portal/CRM/Agents/Performance por esta mudança. O PR #94 no Portal permanece draft, sem merge. Avançar com evidências de testes por SHA/PR.
