# Portal → Sites: exportador Onepage no banco PG18 e vínculo de release

**19/11/2026 — candidata de laboratório, sem autoridade comercial.**

## Objetivo

Conferir que dois produtores puros do Portal — exportador de snapshot rico
Onepage e compositor do plano editorial — podem alimentar o fluxo **persistente**
já implementado no Sites. Até aqui existiam apenas as provas offline
das PRs [Portal #969](https://github.com/rafhael100/imagine-portal/pull/969),
[#970](https://github.com/rafhael100/imagine-portal/pull/970) e
[#972](https://github.com/rafhael100/imagine-portal/pull/972).
A candidata presente acrescenta uma execução do SQL real em cluster isolado.
Ela **não** lê briefing oficial, não ativa produtor Portal nem concede um
novo transporte, provider ou publicação.

## Cadeia sob teste

- Base Sites: [#540](https://github.com/rafhael100/imagine-sites/pull/540),
  SHA `3ac4e9c7d3106b57096b6eb7143a057d0267de35`.
- Fonte Portal: #972, SHA
  `7acee97ebaf474d630415ca456a616b5e12f2df7`, checkout read-only
  sob `.pinned-portal`; o SQL e as migrations do Sites não são modificados.
- Só no opt-in de CI `SITES_PORTAL_BRIEF_EXPORT_CI=1`, a fixture do
  registro nativo cria o projeto Onepage e passa seu recibo original ao
  `SiteContractSnapshot` candidato do Portal.
- O encoder Portal produz os **bytes originais** do corpo e do manifesto,
  com `sourceVersion`, `snapshotRef` e `briefRevision` explícitos.
  `BriefSnapshotInput` do Sites valida e `PortalBriefSnapshotRecorder`
  armazena a revisão, sem presumir que `sourceState=approved` seja prova
  de aprovação na origem.
- O compositor Portal usa uma **seleção editorial separada**, o digest do
  HTML do renderer Sites e a referência do snapshot recém-armazenado.
  `BriefRenderPlanInput`, `StoredBriefReleaseRegistration` e
  `StoredBriefReleaseReceiptReader` exercitam a cadeia técnica no mesmo
  PostgreSQL18 com identidades, grants e tenants sintéticos.
- A primeira revisão do projeto principal e uma revisão posterior
  gerada pelos mesmos produtores confirmam a evolução explícita da fonte
  e da revisão. O histórico é conservado e testado pelos leitores
  já existentes. Checks de rollback e de limpeza dos recursos continuam
  a ser executados pela fixture herdada.

## Controles de CI

`.github/workflows/sites-portal-brief-export-pg18.yml` roda **somente
PR draft** sobre a base exata da #540. As versões PHP 8.3 e 8.4
fazem o checkout do Portal pela SHA acima sem persistir credenciais.
Os dois scripts públicos offline Portal também são executados.
A prova PostgreSQL usa o runner já estabelecido, com Postgres 18
baixado mediante assinatura, cluster em diretório temporário,
certificado TLS de laboratório, usuários sintéticos separados e
migrations reversíveis. Os contadores do registro preexistente
permanecem independentes da marcação da prova do produtor;
o marcador `PORTAL BRIEF PRODUCER PG18 PASS` só deve aparecer
**depois** de persistência/registro, histórico e verificações de rollback.

Não há HTTP público, deploy VPS, alteração Cloudflare, pagamento,
publicação, gasto, cliente real, credencial operacional ou merge.

## O que esta prova não fornece

O manifesto tem `sourceState=approved` por exigência do contrato
candidato, mas o exportador **não possui autorização da origem**.
O Portal D0 atual publica apenas `briefing_available`, não os bytes
oficiais nem uma aprovação imutável do cliente vinculada àquele
conteúdo. Ainda faltam um produtor/leitor Portal autorizado,
prova da autorização/revogação e vínculo comercial atual, custódia
de materiais, transporte autenticado e recibo durável de entrega
do snapshot. O texto editorial separado exige revisão humana
ou fluxo de IA devidamente aprovado; o HTML continua inerte
e os dez gates de produção seguem bloqueados.

## Estado da verificação

Código e workflow preparados em branch isolada. Resultados finais
da execução de GitHub Actions devem ser conferidos antes de
declarar o ensaio PG18 validado. Nenhum job anterior deve ser
apresentado como prova do novo ramo.
