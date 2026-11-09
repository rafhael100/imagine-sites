# SITES-F1-20 — Inventário e testes de paridade do Portal legado Node/SQLite

**Referência técnica consultada em 09/11/2026:** `rafhael100/imagine-portal@main:server/briefing.mjs` e `server/production.mjs`, somente leitura, sem alteração, migração de dados ou reinício do Node.

**Objetivo:** capturar decisões funcionais relevantes do protótipo para a migração progressiva, mas **não transportar** o banco SQLite/usuários/comercial para o Sites. A fonte da verdade da contratação, papel de cliente, pagamento, briefing oficial, aceite e ENTREGUE permanece no Portal Laravel.

## Matriz de comportamento observado

| Comportamento legado | Adaptação candidata | Estado / diferença |
|---|---|---|
| Briefing liberado após estados `CONFIRMED/RECEIVED/RECEIVED_IN_CASH` | Consulta de elegibilidade **oficial** do Portal, nunca confiar em string enviada pelo cliente | Simulado, não integrado |
| Somente cliente titular edita/envia briefing | Política tenant + order ownership verificada no backend Portal | Regras de laboratório, não autenticação |
| Cliente edita apenas `draft/revision_requested` e usa versão otimista | Manter revisão monotônica, bloquear escrita stale e avisar cliente | Paridade sintética |
| Envio de briefing exige empresa, serviços, introdução ou apoio IA, 1 contato e confirmação de materiais | Preservar checklist e confirmação expressa; uploads seguros em storage privado | Paridade parcial, uploads reais pendentes |
| Logo e manual enviados ou declaração de indisponibilidade | Preservar checklist sem obriga-lo a enviar arquivos que não possui | Paridade sintética |
| Revisão exclusiva Imagine e nota para pedido de ajuste | Autoridade admin Imagine e nota contextual na revisão | Paridade sintética |
| Aprovação do briefing exige domínio presente | **Decisão de produto a revisar:** prévias e produção podem ocorrer sem DNS definitivo | Não adotar automaticamente |
| Produção usa estados preparation→production→review→approved→published, 7 dias úteis e até 2 rodadas incluídas | Conservar estados e experiência como referência; prazo e limite devem ser extraídos do contrato comercial aprovado, não hardcoded | Não homologado |
| Prévia antiga com URL HTTPS e release numérica | Exigir release imutável + SHA-256 de **bytes** + prévia autorizada | Reforço de segurança implementado em laboratórios |
| Cliente aprova por `release === current.release` e `confirmed=true` | Exigir titular, confirmação expressa, autorização vigente, release ID **e digest exatos**; N+1 não herda aceite | Reforço de segurança |
| `publish` alterava stage para published após URL HTTPS enviada por admin | Só comprovação independente da release realmente observada pode validar publicação técnica. **ENTREGUE** somente no Portal após checklist | Quebra proposital da semântica insegura antiga |
| Auditoria em `production_events` e `audit` | Eventos de negócio transacionais, autoria e rastreabilidade sem dados sensíveis; não mesclar DBs | Exige implementação/homologação |

## Implementação desta etapa

- `src/Compatibility/LegacyPortalBriefingGuard.php` preserva blockers de envio/revisão do briefing a partir de inputs **fictícios e confiáveis somente no teste**.
- `src/Compatibility/ExactReleaseDecisionGuard.php` modela decisão de cliente por revisão+digest, rejeita silêncio e admin tentando aprovar, impede publish técnico sem observação.
- `tests/f20-run.php` valida positivos/negativos, cross-tenant, versão stale, materiais, diferenças em domínio e versão de HTML.

**Limites:** não foram migrados usuários/arquivos/brieﬁngs reais, rotas de cliente, estado financeiro, obrigações comerciais, armazenamento, upload MIME ou eventual planilha. Campos do legado podem conter dados pessoais: **nenhum dado real foi copiado para o novo repositório**. A matriz de equivalência não aprova regras comerciais divergentes nem uma API operacional.

**Gates:** P0/RF-PEND/RV-CT/LGPD/AppSec continuam abertos. Não publicar, não fazer merges, não alterar main ou a VPS. AGI-11-RB01 permanece desabilitado.
