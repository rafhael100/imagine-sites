# SITES-F1-20 — Inventário e testes de paridade do Portal legado Node/SQLite

**Referência histórica consultada em 09/11/2026:** `rafhael100/imagine-portal@main:server/briefing.mjs` e `server/production.mjs`, somente leitura, sem alteração, migração de dados ou reinício do Node. A reconferência abaixo fixa a origem no HEAD da PR Portal #941, sem depender de uma referência móvel a `main`.

**Objetivo:** capturar decisões funcionais relevantes do protótipo para a migração progressiva, mas **não transportar** o banco SQLite/usuários/comercial para o Sites. A fonte da verdade da contratação, papel de cliente, pagamento, briefing oficial, aceite e ENTREGUE permanece no Portal Laravel.

## Matriz histórica de comportamento observado

Os estados de adaptação desta matriz registram a etapa F1-20. Não substituem o estado das entregas posteriores nem tornam a implementação do protótipo uma autoridade operacional para o Sites.

| Comportamento legado | Adaptação candidata | Estado / diferença |
|---|---|---|
| Briefing do cliente liberado após estados `CONFIRMED/RECEIVED/RECEIVED_IN_CASH`; o legado dispensa esse gate para admin em leitura/revisão | Consulta de elegibilidade **oficial** do Portal, nunca confiar em string enviada pelo cliente ou herdar a dispensa administrativa | Simulado, não integrado |
| Somente cliente titular edita/envia briefing | Política tenant + order ownership verificada no backend Portal | Regras de laboratório, não autenticação |
| Cliente edita apenas `draft/revision_requested`; salvar, enviar e revisar conferem a versão corrente | Manter controle de concorrência, distinguindo contador agregado da origem e revisão imutável de conteúdo | Paridade sintética; upload/remoção não recebem versão esperada no handler legado |
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

- `src/Compatibility/LegacyPortalBriefingGuard.php` é uma adaptação parcial de blockers de envio/revisão a partir de inputs **fictícios e confiáveis somente no teste**. Não é a origem normativa dos campos, unidades ou permissões do legado; as diferenças reconferidas estão abaixo.
- `src/Compatibility/ExactReleaseDecisionGuard.php` modela decisão de cliente por revisão+digest, rejeita silêncio e admin tentando aprovar, impede publish técnico sem observação.
- `tests/f20-run.php` valida positivos/negativos, cross-tenant, versão stale, materiais, diferenças em domínio e versão de HTML.

## Fonte fixada em 18/11/2026

Reconferência somente por leitura de árvore e código de `rafhael100/imagine-portal`, PR #941, commit **`0e0ccc06755b6ddd938635e5c4c3625ff6497e2c`**. Nenhum servidor, teste, banco, upload ou dado de cliente foi executado ou consultado nesta reconferência. Os links fixam o commit; a coluna registra o SHA Git do blob efetivamente lido.

| Fonte | Blob SHA | Papel observado |
|---|---|---|
| [server/briefing.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/briefing.mjs) | `15b662867d740468bf4e003814afb37eb9953c1d` | Campos, validações, estado corrente, operações e materiais |
| [briefing-ui.js](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/briefing-ui.js) | `082211e0f425da7eecf763c3950ddbf6db903ac4` | Formulário real, labels, confirmação e chamadas dos handlers |
| [server/app.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/app.mjs) | `df032d6bf952d6e8e79d547c867c60f0ba784152` | Rotas autenticadas, proprietário do pedido, sessão, CSRF e exclusão por pedido |
| [server/store.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/store.mjs) | `dc319f93b11b4769c7a595a58483384653e3033d` | Identidade de pedido/usuário e catálogo legado de produtos |
| [server/team.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/team.mjs) | `40fcb5991f778dfecb7b33a031dba5b6bb53d572` | Restrições de colaboradores, acesso aos pedidos e revisão pelo atendimento |
| [server/production.mjs](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/server/production.mjs) | `99c49144db23e5fbf70ff951c6c9333d6eb15996` | Produção condicionada a pagamento e briefing aprovado; versão de produção distinta |
| [SiteBriefDraft.php](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/app/Domain/SiteIntake/SiteBriefDraft.php) | `a956ef024111bdd47b0667057d8746a69c8b3d9b` | Objeto SAMPLE separado; não é o briefing persistido Node |
| [SiteBriefPreview.php](https://github.com/rafhael100/imagine-portal/blob/0e0ccc06755b6ddd938635e5c4c3625ff6497e2c/apps/portal/app/Domain/SiteIntake/SiteBriefPreview.php) | `cd757ae3c57a8924350c50134d78bc3a521a5b60` | Validação sintética Onepage/Landing com organizações e referências DEMO |

### Campos e unidades da origem

O formulário e o handler compartilham **11 campos textuais e 3 flags**. Estes são os limites efetivos do handler, medidos por JavaScript `String.length`, portanto **unidades UTF-16**, antes de aplicar `trim()`:

| Campo textual | Máximo legado em unidades UTF-16 |
|---|---:|
| `company_name` | 160 |
| `company_intro`, `services` | 10000 cada |
| `current_site`, `contact_site` | 800 cada |
| `domain` | 253 |
| `email` | 254 |
| `phone`, `whatsapp` | 60 cada |
| `address` | 600 |
| `notes` | 5000 |

As flags são `ai_support`, `logo_unavailable` e `manual_unavailable`; o handler transforma cada uma no resultado de `value === true`. Para texto ausente ou nulo, usa string vazia; descarta chaves não enumeradas e apara os extremos dos textos aceitos. URLs de `current_site`/`contact_site` devem usar HTTP ou HTTPS sem usuário/senha; e-mail tem uma validação simples; domínio é um hostname ASCII, sem esquema/caminho. Essas tolerâncias e normalizações são comportamento observado, não uma exigência para um novo contrato fechado.

`LegacyPortalBriefingGuard.php` cobre apenas parte dos campos e usa `strlen()` em bytes UTF-8, além de uma checagem explícita de UTF-8. Não equivale ao limite Node: um caractere astral ocupa duas unidades UTF-16 e pode ocupar quatro bytes UTF-8. A definição de comprimento de string consta em [ECMAScript §6.1.4](https://tc39.es/ecma262/multipage/ecmascript-data-types-and-values.html#sec-ecmascript-language-types-string-type). Tampouco [`maxLength` de JSON Schema](https://json-schema.org/draft/2020-12/json-schema-validation#section-6.3.1), medido em caracteres Unicode, traduz sozinho o limite do legado. Uma proposta deve declarar suas unidades e registrar diferenças, sem alegar paridade automática.

### Identidade, estado e operações

O briefing corrente é identificado por `order_id`; o pedido referencia `user_id` e `product_id`. A rota resolve o pedido com `own()`, a sessão e o guard de equipe antes de chamar o handler. Para edição/envio, exige cliente titular, produto `site`, pagamento em um dos três estados legados e estado ausente, `draft` ou `revision_requested`. Colaboradores têm restrições próprias: atendimento pode revisar; produção consulta materiais de pedidos atribuídos. Isso não fornece `tenantRef`, vínculo organizacional nativo ou identidade autenticada entre Portal e Sites.

| Operação | Regra observada |
|---|---|
| Salvar | Valida e substitui o JSON corrente; permite conteúdo incompleto; exige versão esperada igual à corrente e a incrementa |
| Enviar | Usa o conteúdo já salvo; exige nome e serviços, apresentação ou apoio IA, ao menos um contato, logo/manual ou suas declarações de indisponibilidade e `confirmed === true`; muda para `submitted` e incrementa a versão |
| Revisar | Exige `submitted`; Imagine aprova ou solicita complemento; nota aparada de até 4000 unidades UTF-16 e pelo menos 5 para solicitar complemento; aprovação exige domínio presente; incrementa a versão |
| Enviar/remover arquivo | Exige edição autorizada e incrementa a mesma versão; esses handlers não recebem versão esperada; o formulário salva o rascunho antes de chamá-los |

`version` começa em zero e é um **contador agregado da origem**, não uma revisão exclusiva do texto: salvar, submeter, revisar e alterar arquivos avançam o mesmo número. Salvar/enviar/revisar validam `Number.isSafeInteger(expected)` e igualdade com o valor corrente. Há somente o JSON corrente por pedido, acompanhado de estado, datas e última nota de revisão. Não há nesse fluxo um histórico imutável de corpos, digest canônico do briefing ou recibo de transporte. Os eventos de auditoria não substituem esses artefatos. A versão numérica de produção/prévia pertence a outro fluxo.

### Materiais e diferenças que permanecem abertas

Materiais são de tipo `logo` ou `manual`: logo aceita PNG, JPG/JPEG, WebP ou PDF; manual aceita PDF, com checagem de extensão e assinatura. Os limites são **10 × 1024 × 1024 bytes por arquivo, 50 × 1024 × 1024 bytes por pedido e 10 arquivos**. O nome tem 1 a 180 unidades UTF-16 e não pode conter barras, controles C0 ou DEL. IDs são UUIDs gerados pelo servidor; a visualização retorna `id`, `kind`, `original_name`, `mime`, `size` e `created_at`, sem nome de armazenamento ou autor interno. O armazenamento é privado e o download revalida acesso ao pedido. O fluxo não fornece digest do material nem revisão imutável de seu conteúdo.

- **Pagamento e domínio:** `ensure()` dispensa o gate de pagamento para admin em leitura/revisão; iniciar produção volta a exigir pagamento e briefing aprovado. Essa dispensa não deve ser homologada implicitamente. O domínio aparece no checklist, mas não é condição de submissão; é condição de aprovação no legado. Os três estados financeiros, a exigência de domínio, prazos e rodadas não substituem decisões e evidências nativas do Portal.
- **Onepage e Landing:** os handlers e o formulário inspecionados restringem o fluxo a `product_id = site`, cujo catálogo o identifica como Site Onepage. Nesse catálogo Node não há um produto Landing Page. O caminho Onepage/Landing encontrado em `SiteBriefDraft`/`SiteBriefPreview` é SAMPLE, limitado a `org-a`/`org-b` e `DEMO-SITE-REQ-*`, com autoridade sempre falsa. Não demonstra um briefing Landing persistido nem autoriza herdar todos os campos/requisitos do Onepage.
- **Conteúdo rico e produção:** a origem concreta sustenta uma proposta reversível para o briefing rico Onepage. Não determina automaticamente `headline`, `callToAction` ou uma lista `benefits` do renderer atual; serviços são texto livre. Mapeamento editorial, versão imutável, referências de materiais verificáveis e envelope autenticado exigem definição explícita, sem fabricar conteúdo ou transformar o protótipo em autoridade.

Este inventário registra a evidência e suas diferenças. A proposta de adoção pertence ao [contrato candidato de briefing e produção](SITES-BRIEF-PRODUCTION-CONTRACT-CANDIDATE-v0.1.md), sem duplicar aqui seu schema ou aprovar transporte, integração operacional ou geração automática.

**Limites:** não foram migrados usuários/arquivos/brieﬁngs reais, rotas de cliente, estado financeiro, obrigações comerciais, armazenamento, upload MIME ou eventual planilha. Campos do legado podem conter dados pessoais: **nenhum dado real foi copiado para o novo repositório**. A matriz de equivalência não aprova regras comerciais divergentes nem uma API operacional.

**Gates:** P0/RF-PEND/RV-CT/LGPD/AppSec continuam abertos. Não publicar, não fazer merges, não alterar main ou a VPS. AGI-11-RB01 permanece desabilitado.
