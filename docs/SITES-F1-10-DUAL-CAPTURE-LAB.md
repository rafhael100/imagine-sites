# SITES-F1-10 — Duas bases descartáveis de captação, vínculo CRM e prazo de 432 horas

**Escopo:** dois serviços PostgreSQL temporários em um único GitHub Actions runner, com dados e endpoints de teste; nada é publicado, nenhuma API real é chamada e nenhum dado pessoal é tratado.

## Validação proposta em laboratório

Cada banco (`sites_ingress_primary_lab` e `sites_ingress_secondary_lab`) tem tabelas próprias para vínculos versionados e autorizados, recibos duráveis de recebimento sintético e tentativas de entrega, sem payload de leads.

- Cada recibo associa `tenant_ref`, site, revisão do vínculo, **destino CRM original**, digest fictício, `T0` e `deadline=T0+432h`.
- Duplicata `(tenant_ref,submission_id)` bloqueada por unicidade em cada banco.
- Troca de vínculo não redireciona recibos anteriores ao CRM novo; revogação bloqueia **novos** ingressos pelo vínculo antigo.
- Tentativas após prazo de 432h, exatamente no limite e depois de entrega confirmada são bloqueadas pelo trigger experimental.
- O segundo banco tem schema e dados próprios, e uma simulação de endpoint principal indisponível faz o teste recorrer ao segundo banco.
- A faixa inicial de 72h e a recuperação de 360h permanecem definidas em IN-127 e na política PHP `LeadDeadline`; o total de 432h não reinicia após reenvio ou rota de contingência.

## Limites CRÍTICOS

**Não existe ainda captação em produção, API principal/contingência real, independência física entre provedores/servidores, autorização real do formulário, payload protegido de dados pessoais, confirmação do CRM, criptografia, DNS, failover de rede ou descarte de backups.** Dois contêineres do mesmo runner apenas simulam armazenamento independente; não atendem ao requisito de infraestrutura fisicamente independente de produção.

O fluxo em SQL usa o owner `postgres` **somente para fixtures**; não é uma implementação de privilégios do runtime. As mensagens de falha expõem somente IDs fictícios. Um sistema real precisa validar o vínculo, evitar alterações do tempo de `T0` pelo cliente, proteger dados em repouso, registrar origem e idempotência end-to-end e implementar descarte/auditoria/legal hold conforme normas. A comparação com o relógio do DB demonstra bloqueio, mas não substitui scheduler/retention worker/restore seguro.

**Gates:** P0-DOC03/05/07, IN-124/127/128/147, RF-PEND-04, segurança LGPD e custos. Sem merges/deploys automáticos, dados reais, VPS, Cloudflare ou abertura de formulários. AGI-11-RB01 desabilitado.
