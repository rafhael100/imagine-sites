# Próxima integração central: Portal → briefing → geração

## Decisão de continuidade

**18/11/2026.** O próximo avanço central é receber uma revisão oficial de briefing do Portal e fazê-la alimentar uma produção persistente no Sites. Falta um contrato verificável desse insumo e um consumidor definido para ele. Criar agora um armazenamento genérico em `sites_core` não ligaria o fluxo existente e anteciparia decisões que pertencem às duas aplicações.

Essa conclusão delimita a integração; não afirma esgotamento de todo desenvolvimento. O trabalho autorizado em branch isolada pode continuar em componentes com uso concreto e defeitos demonstrados. A inspeção privada do diário é um exemplo: consome a API persistente existente sem criar autoridade ou depender de um briefing inventado.

## Evidência do Portal inspecionada

Foram consultados títulos, escopos e referências das PRs #924–#936. As HEADs #924, #935 e #936 foram reconferidas nesta revisão. PR mais recente não significa contrato mais recente da integração: existem linhas paralelas.

| Referência fixada | Evidência e limite |
| --- | --- |
| [#924, `ed9333f0`](https://github.com/rafhael100/imagine-portal/blob/ed9333f0b7fe8f2c1207ef13ffb4302755e2ef6c/apps/portal/labs/onepage-atomic-read/README.md) | Leitura do pedido próprio no laboratório; conserva `pending_payment`, `settled=false`, `preview_admitted=false` e vínculo ausente. Não autoriza prévia nem fornece o briefing oficial. |
| [#925, `1577cdab`](https://github.com/rafhael100/imagine-portal/commit/1577cdab6560424603d1ace363be20a6b28cc254) e [#926, `7c42a13c`](https://github.com/rafhael100/imagine-portal/commit/7c42a13c03e7f98c1626f3950b9f19bf2f77d4a4) | Reserva e vínculo Onepage em PostgreSQL descartável; os escopos declaram simulação e ausência de baixa operacional/prévia Sites. |
| [#928, `951e02e5`](https://github.com/rafhael100/imagine-portal/commit/951e02e5d8eedee93851416778027cf07d105296), [#933, `f8a1c309`](https://github.com/rafhael100/imagine-portal/commit/f8a1c3098ee8e3098773c4cdec459841670542f0) e [#936, `815ffc5e`](https://github.com/rafhael100/imagine-portal/commit/815ffc5eeeddc928d631e9ba3a5cf2aca35ff233) | Composição Onepage, autenticação nativa no Kernel e diagnóstico limitado, todos de laboratório. Não conectam Sites nem ativam pagamento ou produção. |
| [#927, `30715cf4`](https://github.com/rafhael100/imagine-portal/commit/30715cf49355a8aad39c2ff8c989dcd1cb2566d9); [#929, `58aa1ae4`](https://github.com/rafhael100/imagine-portal/commit/58aa1ae4b82cc0fc2bbe8cb481d62c50c6fd136a), [#930, `ed6a4f75`](https://github.com/rafhael100/imagine-portal/commit/ed6a4f75c20064ae78c433ceabcac636065fc8e3), [#931, `26075ce0`](https://github.com/rafhael100/imagine-portal/commit/26075ce0b409a32419bc2919270d4e25cb609772), [#934, `b3408c49`](https://github.com/rafhael100/imagine-portal/commit/b3408c49fbf3a245c3d393ce4794da7fabd1d534) | Frentes Performance/GSC. Seus títulos e escopos não entregam contrato de briefing para Sites. |
| [#932, `82deeb00`](https://github.com/rafhael100/imagine-portal/commit/82deeb0062f80f1cf979a2c0105d394c2b1adc23) e [#935, `49685869`](https://github.com/rafhael100/imagine-portal/commit/4968586998f79f892a7c41da417cf762345df2b6) | Preservação de contexto visual no cadastro/login e correção de expectativas de teste de sessão; não constituem nova origem de briefing. |

A leitura de código reforça esse limite. [SiteBriefDraft na HEAD #936](https://github.com/rafhael100/imagine-portal/blob/815ffc5eeeddc928d631e9ba3a5cf2aca35ff233/apps/portal/app/Domain/SiteIntake/SiteBriefDraft.php) continua explicitamente SAMPLE, restrito a organizações e referências DEMO. O [F48 na HEAD #935](https://github.com/rafhael100/imagine-portal/blob/4968586998f79f892a7c41da417cf762345df2b6/apps/portal/labs/sites-intake-f48/README.md) define somente metadados de prévia, exclui corpo do briefing/assets e mantém transporte, aceite e publicação desautorizados. Esses arquivos conservam os mesmos blobs inspecionados na #924. **Não foi encontrado contrato oficial de corpo de briefing nessas referências**; isso não presume inexistência em fontes não fornecidas.

## O que as peças Sites já fazem

| Peça atual | Consequência para a integração |
| --- | --- |
| [SiteProjectCommand](../../src/Integration/SiteProjectCommand.php) | Comando candidato de projeto com referências comerciais; não transporta corpo/revisão de briefing nem comprova autoridade. |
| [SiteBrief](../../src/Production/SiteBrief.php), [PrivatePreviewWorkspace](../../src/Production/PrivatePreviewWorkspace.php) e [SQL do workspace](../../contracts/private-preview/001-workspace.sql) | Persistem e renderizam os cinco campos de demonstração. `demonstration_only` é uma restrição explícita; salvar esses campos em outro schema não os torna um briefing oficial. |
| [EditorialRevisionWriter](../../apps/sites/app/Editorial/EditorialRevisionWriter.php) | Avança o contador a cada transição, incluindo submissão, aprovação interna e reabertura, recebendo digest. Portanto, revisão editorial não identifica uma versão do insumo nem pode substituí-la. |
| [LaravelAdmittedPreviewLab](../../apps/sites/app/Labs/LaravelAdmittedPreviewLab.php) | Usa texto fictício fixo, sob guardas de CI. Não lê briefing do cliente e não é worker de geração operacional. |
| [Pacote técnico](../../ops/release-candidate/README.md), [diário](../../contracts/publication-journal/README.md) e [inspector](../../src/Publication/PrivatePublicationInspection.php) | Trabalham sobre artefato/vínculo e estado técnico já existentes. Não fornecem o insumo ausente, não verificam pagamento/aceite e não autorizam envio. |

Os nomes lógicos `SubmitBriefRevision` e `RequestGenerateRelease` já constam em [SITES-CT-01](../SITES-CT-01-PORTAL-SITES-v0.1.md), explicitamente candidato e não homologado. Eles não definem por si só API, payload ou consumidor implementável. Um snapshot interno só terá uso concreto quando houver origem e consumidor que preservem a identidade da mesma revisão durante a produção.

## Insumos necessários para abrir a implementação central

1. **Contrato versionado do Portal, com responsável e SHA revisável:** fonte oficial, regras de revisão/substituição/conflito, conteúdo permitido e vínculo com pedido/projeto/produto. Fornecer exemplos inteiramente sintéticos compatíveis e identificar quais regras do legado devem ser preservadas. Este documento não escolhe campos, endpoint ou envelope.
2. **Leitura e entrega autorizadas:** identidade e escopo por empresa/recurso, revogação corrente, acesso aos materiais privados, custódia/retenção e comportamento de deduplicação/confirmação em falhas. Referência recebida no JSON não concede acesso. Resolver essas definições no contrato antes de desenhar novas tabelas e grants.
3. **Primeiro consumidor de produção:** determinar qual operação autorizada usa o insumo, qual saída constitui uma candidata de release e como comprovar sua relação com a revisão recebida. Para uso de IA/ferramenta externa, definir previamente ferramenta, orçamento, dados permitidos e revisão humana; não reutilizar o fixture de texto fixo como execução real.

Com esses insumos, é possível implementar em draft o parser/adaptador estrito, persistência imutável no banco próprio do Sites e consumidor da revisão exata, mantendo revisões de briefing e editorial distintas. O ensaio pareado deve usar fontes fixadas dos dois repositórios e provar isolamento, repetição, conflito de revisão, revogação e recuperação após interrupção sem produzir duas saídas para a mesma operação. A migração, API e política de retomada devem derivar do contrato acordado.

## Dependências operacionais e alçadas preservadas

O [staging privado](../SITES-P0-PRIVATE-STAGING-2026-11-16.md) e a [verificação real de TLS PostgreSQL](../SITES-P0-EXISTING-TLS-2026-11-16.md) já possuem evidência própria. Isso não instala os novos candidatos nem comprova a cadeia comercial. Continuam necessários produtor nativo homologado de pedido/contrato, identidade S2S e revogação operacionais, credenciais/escopo Sandbox por canal privado e confirmação financeira independente, conforme a [matriz integrada](../SITES-P0-LEASEWEB-INTEGRATED-REHEARSAL-2026-11-15.md). Não se deve repetir como pendente a prova de TLS já executada, nem inferir que ela libera essas outras dependências.

A autorização vigente permite desenvolver e revisar candidatos isolados. [AGENTS.md](../../AGENTS.md) continua exigindo alçada específica para merge/deploy, VPS, DNS/Cloudflare, APIs reais, gastos, credenciais e dados de clientes; operações limitadas documentadas anteriormente não autorizam uma nova instalação. O Portal conserva contrato, briefing, aceite expresso da versão exata e `ENTREGUE`; Sites conserva artefato e publicação técnica. Aceite, backup externo/restore, observação independente da publicação e homologação mantêm suas evidências próprias. Os [dez gates](../../config/operational-readiness.json), [P0/RF-PEND e bloqueio de rollback automático](../SITES-SEC-01-GATES.md) permanecem inalterados.

**Critério para retomar esta frente:** contrato e consumidor identificados permitem iniciar código e CI pareado; fontes, credenciais e autorização do ambiente permitem preparar depois a operação concreta para revisão. O bloqueio atual da integração central é essa falta de definição/evidência, não a necessidade de pedir novamente autorização a cada entrega de código.
