# F1-137 — Prova de que o tenant GUC não autentica a organização

## Objetivo
O laboratório PostgreSQL 18 de F1-133 usa RLS com `current_setting('app.tenant_id')` para filtrar linhas por organização. A F1-137 demonstra uma limitação crítica: a própria role `sites_f133_lead_app`, se puder enviar SQL arbitrário, consegue configurar esse valor para o UUID sintético de outra organização. Assim, uma consulta RLS pode retornar a linha dessa organização, embora a identidade do cliente não tenha sido comprovada.

## Regressão executada
O teste `tests/f133-pg-lead-idempotency.php` cria duas organizações e duas conexões independentes na base descartável do CI. Depois de persistir os registros técnicos da segunda organização, outra conexão com a **mesma role de aplicação** define `app.tenant_id` com o identificador dela. O teste prova que a consulta torna visível esse registro e que `LabPostgresCrmLeadMetadataReadback` retorna somente `METADATA_MATCH_UNVERIFIED`, sem reservar submissão, reconhecer o visitante, confirmar entrega ao CRM nem autorizar reenvio. Após rollback, o contexto local desaparece.

A demonstração usa apenas UUIDs fictícios, banco descartável, dados sem PII e contas CI. O resultado **não é evidência de acesso autorizado**; é uma prova de que a GUC pode ser forjada por quem executa SQL.

## Consequência de arquitetura
Antes de qualquer endpoint operacional, exigir autenticação de serviço e usuário, autorização de empresa por uma autoridade independente e confiável, conexão restrita sem SQL arbitrário exposto ao usuário, revisão AppSec/DBA da estratégia de RLS, e provas adversariais de isolamento na implantação real. RLS com GUC definido pela aplicação pode ser uma camada adicional, nunca a única fonte de identidade.

OP04 #36 permanece P0, com os dez gates de produção bloqueados. Sem deploy, merge em dev/main, dados reais, Cloudflare, DNS, VPS ou novos custos. PR em draft, empilhado sobre F1-136.
