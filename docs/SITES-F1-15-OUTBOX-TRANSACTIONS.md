# SITES-F1-15 — Contrato de comando e outbox transacional em PostgreSQL de laboratório

**Objetivo:** provar propriedades de persistência atômica e controle de acesso em PostgreSQL 18 temporário, sem integrar Laravel ou o Portal real.

O banco descartável `sites_lab` recebe `lab_sites.project_commands` e `lab_sites.project_outbox` com FK para projetos fictícios F1-05. O recorte usa uma transação para gravar um comando admitido e o respectivo evento `sites.project.admitted.v1`. O consumidor comercial no Portal continuará responsável por fonte oficial de contrato; o Sites precisará revalidar autorização antes de admitir qualquer comando real.

**Proteções do laboratório:** UNIQUE por tenant+operação, tenant+idempotency, evento; revisão de contrato da linha de projeto; tipos/versionamento imutáveis; evento pendente obrigatório; papel mínimo sem UPDATE/DELETE; RLS `ENABLE/FORCE`, negação cross-tenant e acesso bloqueado após revogação. Rejeição do segundo evento provoca rollback de ambos os registros num subtransaction de teste. Um segundo processo `psql` lê os registros após commit, para provar persistência durante o job.

**Não confundir com implementação final:** não existe ainda dispatcher, receipt assinado do Portal, replay end-to-end, idempotência de resposta em concorrência real, rotação, SLA, outbox worker, auditoria durável de envio, filas dead-letter ou compensação de cobrança. O `accepted` de laboratório **não** autoriza publicar nem representa entrega oficial. A verificação em SQL de revisão do contrato vem da fixture de DB, não da fonte financeira do Portal.

A fonte de identidade usada em CI são roles de banco artificiais, iniciadas pelo schema owner somente no ambiente efêmero. Não usar superuser nem SET ROLE em produção, não permitir que cliente/IA escolha role ou tenant. Este SQL não pode ser promovido diretamente a migration do Laravel.

**Gates:** P0-DOC01..14, RF-PEND-01/04, RV-CT, LGPD/AppSec, restore anti-ressurreição e evidências de concorrência permanecem em aberto. Sem merge/deploy automático, dados reais, Cloudflare, CRM, agentes ou VPS. AGI-11-RB01 desabilitado.
