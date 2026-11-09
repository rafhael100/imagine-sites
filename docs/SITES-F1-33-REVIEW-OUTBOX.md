# F1-33 — Fila transacional de propostas de revisão do Portal (PostgreSQL descartável)

A etapa adiciona uma tabela append-only `lab_sites.portal_review_outbox` no banco descartável `sites_lab`, vinculada ao evento de revisão F1-32 por chave estrangeira (empresa, site, workflow, revisão). O registro tem revisão e hash exatos, status `pending`, `official_client_acceptance=false`, `publishable=false`.

## Prova sintética
- Trigger PostgreSQL verifica que a revisão de origem é **a última revisão** e foi `internal_approved`, com o SHA-256 idêntico ao submetido.
- Uma mesma revisão de workflow só pode originar uma proposta, graças à unicidade; repetir `proposal_ref` também falha.
- ACL permite somente `SELECT/INSERT` à role fictícia; sem `UPDATE/DELETE` do status nem capacidade de transmitir.
- RLS rejeita leitura/escrita interempresa; revogação fictícia nega novas inserções e leitura.
- Tentativa de registrar revisão incompleta dentro da mesma transação aborta os registros parciais. Um retorno posterior à produção impede **nova criação** de proposta obsoleta.
- Timestamp é gerado pelo banco, não pelo remetente.
- CI `sites-f1-33-review-outbox.yml` executa PostgreSQL 18 de uso temporário, sem dados reais.

## Falhas e bloqueios deliberados
**Não existe dispatcher** desta fila, cliente real, transporte autenticado, resposta do Portal, confirmação do titular, aprovação de produção nem evento ENTREGUE. A tabela permite pendências obsoletas após retorno para revisão: o **futuro dispatcher precisa revalidar a versão, revogação, contrato e aceite no momento do envio**, em transação que coordene concorrência. O trigger de laboratório não é prova suficiente contra todas as corridas entre transações, nem contra restauração de backup.

Nenhum código SQL é migration Laravel de produção. As roles do teste não são identidades reais e os flags fictícios não conferem autorização. `OP03/OP05`, P0/RF-PEND/RV-CT, AppSec/LGPD e `config/operational-readiness.json` permanecem BLOQUEADOS. Sem VPS, Cloudflare, DNS, segredos, dados de clientes, gastos ou alteração em `main`; `AGI-11-RB01` desabilitado.
