# SITES-DEV-CONSOLIDATION — Registro auditável da integração supervisionada

**Data:** 09/11/2026 (America/Sao_Paulo).  
**Repositório:** [rafhael100/imagine-sites](https://github.com/rafhael100/imagine-sites).  
**Objetivo aprovado:** integrar os recortes já testados **exclusivamente em `dev`**, com merges controlados/sequenciais, mantendo `main`, Portal, VPS, Cloudflare e produção inalterados.

## 1. Cinco merges realizados

| Fase | PR de integração | PRs fonte | Head de origem verificado | Merge SHA (destino dev) |
| --- | --- | --- | --- | --- |
| A — Base e contrato | [#24](https://github.com/rafhael100/imagine-sites/pull/24) | #1–#4 | `5f07051d259bb94aa574ecde030ddbc4db8463ab` | `1084c0abe12e1c597335ab87d47426ca19cc80f0` |
| B — RLS e produção estática | [#25](https://github.com/rafhael100/imagine-sites/pull/25) | #5–#9 | `e9cef80b24c5125f3166f04ba9a009d48e756e54` | `bd94cb87abca06a2e61d3f247bb6ed0b319ea9e1` |
| C — Contrato/nonce e captação fictícia | [#26](https://github.com/rafhael100/imagine-sites/pull/26) | #10–#13 | `f887113069204591a361b1cd8773465da6493ed9` | `d4b7c94c6c39ab86cd9c8ed38c1886e8d614bf87` |
| D — PDO, outbox e testes integrados | [#27](https://github.com/rafhael100/imagine-sites/pull/27) | #15–#18 | `77f4f46cc559f00bc337c9c0f27df72c2a60a087` | `6050e853390b15786d37fddd5628b162b2d87480` |
| E — API candidata, paridade, segurança e restore | [#28](https://github.com/rafhael100/imagine-sites/pull/28) | #19–#23 | `7456ed0211b8573021d1ec2d5cac0efab4b75a46` | `1f90019a367248681ab90d2549e65ffe970dddec` |

**Conferência Git:** a árvore do merge final E na `dev` (`7b74a6c96956591c27c471bd9782eb2bc8c8017f`) é idêntica à árvore do HEAD original #23. O SHA de `main` permaneceu `e95c481176f48c28ade1db0a5bcd2777fe7dcb3e`.

Em todos os PRs A–E, o alvo foi `dev`; o método usado foi `merge` para preservar os ancestrais. Os PRs originais permanecem draft e não devem ser mesclados diretamente em `main`.

## 2. Evidência de verificação antes/depois

- A (#24): PHP 8.3/8.4 e Laravel em CI — PASS.
- B (#25): PHP/Laravel, PostgreSQL 18 RLS F1-05, release SQL F1-08 — PASS.
- C (#26): PHP/Laravel, duas bases fictícias de captação F1-10 e nonce PostgreSQL F1-12 — PASS.
- D (#27): PHP/Laravel, PDO→PostgreSQL F1-14 e outbox SQL F1-15 — PASS.
- E (#28): PHP/Laravel, OpenAPI/AsyncAPI estáticos F1-18, Laravel↔PostgreSQL F1-19, scanner de CI F1-21 e restore/revogação F1-22 — PASS.
- Após merge E, push de `dev`: `Sites Engine - testes sinteticos (sem deploy)`, `F1-03 Laravel isolado - sintético sem deploy` e `F1-21 CI-only operational safety guard` — PASS.

Limite: nem todos os workflows PostgreSQL especiais são reexecutados em cada push de `dev`; eles foram executados e verificados nos PRs A–E nos recortes relevantes. O teste foi feito em CI temporário e não substitui teste operacional de VPS.

## 3. Decisão técnica sobre PR #14

O [PR #14](https://github.com/rafhael100/imagine-sites/pull/14) propõe outro adaptador PHP/PostgreSQL para a mesma etapa F1-14. Ele é irmão da branch do [PR #15](https://github.com/rafhael100/imagine-sites/pull/15), **não seu ancestral**. Incluir ambos sem análise poderia duplicar caminhos de autenticação/nonce. Foi consolidado o percurso do PR #15, necessário aos descendentes #16–#23. O #14 foi preservado como draft **sem merge**, para eventual extração de melhorias específicas após revisão comparativa; não é um bloqueio para esta integração de laboratórios.

## 4. O que ainda NÃO foi migrado para operação

1. Identidades oficiais, credenciais e transportes autenticados Portal→Sites, assinatura com vault, nonce compartilhado real, revogação e contratos homologados.
2. Migrações Laravel e bancos separados produtivos com role mínimo, auditoria, backups/restore, RLS e ledger de revogação fisicamente independente.
3. Produção de sites com AI Agents/n8n reais e orçamento/aprovação humana, gestão de arquivos e prévia privada para cliente.
4. Publicação real na Cloudflare Workers Static Assets/GitHub, domínio/DNS/SSL, prova independente de conteúdo servido e incidentes.
5. Formulários/CRM reais e armazenamento de leads principal e secundário **fisicamente independentes**, reenvio e descarte em T0+432h com LGPD.
6. Homologação de contratos comerciais, cobrança, benefício CRM cortesia, segurança, disponibilidade, custos, políticas de alçada e primeiro piloto autorizado.

**Gates:** P0-DOC01–14, RF-PEND-01/04, RV-CT, LGPD/AppSec e `config/operational-readiness.json` todos pendentes. `AGI-11-RB01` continua DESABILITADO.

## 5. Política após integração

Apenas `dev` contém os recortes consolidados. O produto **não está pronto para clientes**. Seguir com revisões de arquitetura e testes no `dev` ou em branches filhas, mediante PR e CI. Não mesclar `dev` em `main`, não fazer deploy, não acessar APIs/contas reais nem aprovar gates sem evidências e autorização específica.

Para uma próxima mudança de risco, preparar plano de homologação com critérios verificáveis e pedir a aprovação do responsável **somente nesse gate**, sem interromper por escolhas técnicas rotineiras.
