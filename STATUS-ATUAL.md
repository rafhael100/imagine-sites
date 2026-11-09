# STATUS-ATUAL — Imagine Sites Engine

Data-base 2026-11-08. Recorte atual F1-14, branch filha F1-13.

F1-01..13: domínio, Laravel, CreateProject v1, PostgreSQL/RLS, templates e prévias sintéticas, publicação mockada, duas bases de leads fictícias, HMAC com paridade Portal e nonce persistente em laboratório, HTTP kernel-only com flag; PRs #1–13 draft, CI PASS.
F1-14: adapter `LabPdoReplayStore` para consumo único de nonce assinado contra PostgreSQL temporário usando conta de privilégio mínimo e senha randômica de CI. CI desta etapa deve ser conferido.

Nenhum endpoint operacional, dados pessoais, produção, cloud, CRM, Agents, DNS, VPS ou merge/deploy. Ainda faltam S2S real, tenancy/autorização do contrato, replay store de produção, releases persistentes no Laravel, filas/outbox e homologação P0/RF-PEND/RV-CT. AGI-11-RB01 desabilitado.
