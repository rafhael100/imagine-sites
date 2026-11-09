# SITES-CT-01 — Contratos Portal ↔ Sites (CANDIDATOS v0.1)

**Sem endpoints ativos. Não homologado.** Revisar sob P0-DOC03, AUT-P01..10 e RV-CT01..08.

| Comando/evento lógico | Sentido | Regra |
| --- | --- | --- |
| CreateProject | Portal → Sites | contrato elegível, recurso autorizado, chave idempotente |
| SubmitBriefRevision | Portal → Sites | briefing validado e acesso permitido a materiais |
| RequestGenerateRelease | Equipe/Portal → Sites | orçamento, escopo, ferramenta e estado de job |
| PreviewReady | Sites → Portal | release e digest imutáveis; prévia autorizada |
| RegisterClientAcceptance | Portal → Sites | aceite expresso do ator/tenant, hash exato, prova e revisão |
| RequestPublication | Portal autorizado → Sites | sem aceite, contrato ou domínio válido, bloquear |
| PublicationObserved | Sites → Portal | prova independente de digest e release efetivamente servidos |
| DeliveryEligible | Sites → Portal | apenas parecer técnico; Portal declara ENTREGUE |
| RevokeAuthorization | Portal → Sites | revisão/revogação com barreira segura |

Envelope candidato: schema_version, event_id, event_type, correlation_id, tenant_ref, site_ref, resource_revision, occurred_at UTC, issuer, audience, idempotency_key e payload_ref privado. Sem PII ou material privado no envelope/log.

## Requisitos não negociáveis
1. Autenticação forte entre serviços e autorização por ação/tenant/recurso; a presença de um ID no JSON não garante autoridade.
2. Comandos assíncronos com chave idempotente persistente, deduplicação, replay protection, ordenação por recurso, outbox/inbox e reconciliação.
3. Acceptance vinculada a site, tenant, release, manifesto, contrato e autorização corrente; ausência de prova = falha fechada.
4. HTTP 202 significa comando recebido; **não** prova deploy, pagamento ou CRM lead. Publicação exige verificação real do artefato.
5. Portal indisponível não derruba automaticamente site estático; sem comprovação de autorização atual bloquear efeitos novos.
6. Troca de vínculo CRM não pode redirecionar leads antigos; respeitar deadline de 432h desde T0.

**Limitação F1-01:** PortalEvidence contém somente flags simuladas, não assinatura, sessão, MFA, token, RLS nem autorização. PublicationPolicy é verificador lógico sintético sem poderes operacionais.

Testes futuros: outras organizações, assinatura falsa, token revogado, manifesto não correspondente, efeitos concorrentes, resposta inconclusiva, tentativa duplicada, CRM errado, TTL vencido, restauração de lead expirado.
