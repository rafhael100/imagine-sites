# SITES-HOMOLOGATION-ENTRY — Condições verificáveis para iniciar homologação operacional

**Data:** 09/11/2026. **Status:** BLOQUEADO PARA PRODUÇÃO.

O código de laboratório F1-01 a F1-25 está consolidado no ramo de desenvolvimento `dev` do [Imagine Sites](https://github.com/rafhael100/imagine-sites). Isso **não** representa uma migração operacional concluída: não há integração real de Portal, Sites, AI Agents, CRM, Cloudflare ou VPS. A `main` não foi alterada.

## Evidências já obtidas

| Recorte | Evidência | O que demonstra |
| --- | --- | --- |
| F1-01..22 | [PRs de integração #24–#29](https://github.com/rafhael100/imagine-sites/pulls?q=is%3Apr+is%3Amerged) | Fundação Laravel, políticas, PostgreSQL e CI de laboratório consolidados somente em `dev` |
| F1-23 | [PR #30](https://github.com/rafhael100/imagine-sites/pull/30) | Código real do assinador candidato Portal PR #105 compatível com verificador candidato Sites, mas sem serviço/rede real |
| F1-24 | [PR #31](https://github.com/rafhael100/imagine-sites/pull/31) | Pacotes de HTML sintéticos, privados, com manifesto SHA-256 e `publishable=false` |
| F1-25 | [PR #32](https://github.com/rafhael100/imagine-sites/pull/32) | T0 do recebimento controlado pelo banco, vínculo CRM imutável e limpeza de metadados fictícios em PostgreSQL descartável |

O CI dessas etapas aprovou PHP/Laravel e os testes SQL aplicáveis antes dos merges. Não se deve interpretar esses testes como prova de disponibilidade, autenticidade de clientes, retenção real ou conformidade jurídica.

## Backlog operacional com critérios de aceite

| Ordem | Dependência | Evidência necessária antes do próximo gate |
| --- | --- | --- |
| 1 | [SITES-OP01 — identidade e contratos Portal↔Sites](https://github.com/rafhael100/imagine-sites/issues/33) | Identidade oficial/rotacionável, autorização por empresa/contrato, nonce e revogação duráveis, DENY em falhas |
| 2 | [SITES-OP02 — DB isolado e recuperação](https://github.com/rafhael100/imagine-sites/issues/34) | Migrations, RLS em usuários não privilegiados, outbox e ledger anti-revogação fora da área restaurada |
| 3 | [SITES-OP05 — produção AI Agents/n8n](https://github.com/rafhael100/imagine-sites/issues/37) | Produção pela Imagine com controles de custo, direitos, auditoria, revisão interna e aprovação expressa do cliente |
| 4 | [SITES-OP03 — Cloudflare/publicação](https://github.com/rafhael100/imagine-sites/issues/35) | Domínio de testes autorizado, versão exata aprovada, proof independente de HTML/CDN servido e rollback manual |
| 5 | [SITES-OP04 — leads e CRM](https://github.com/rafhael100/imagine-sites/issues/36) | Dois serviços fisicamente independentes, T0 durável e 432h, idempotência global, confirmação CRM e descarte de dados |
| GATE | [SITES-GATE — ambiente e go-live](https://github.com/rafhael100/imagine-sites/issues/38) | Infra de homologação, vault, DNS, custos/cotas e evidências finais de AppSec/LGPD/P0 |

## Ação que depende exclusivamente do responsável

Para sair dos laboratórios do GitHub e realizar uma **primeira homologação real, sem clientes**, será necessário disponibilizar acesso autorizado ao ambiente de homologação da Leaseweb e a uma conta Cloudflare com um domínio técnico de testes, além da configuração de credenciais de menor privilégio em secret manager. **Não copiar senhas, tokens, certificados ou chave privada para este chat, issues públicos ou repositórios públicos.**

Essa conexão deve ser feita pelo responsável no ambiente de execução autorizado (por exemplo, uma sessão autenticada no ChatGPT Work, caso tenha acesso à navegação por browser), ou por procedimento de secrets/CI corporativo validado. A autorização geral para desenvolver **não** se converte automaticamente em permissão de publicar sites ou DNS. Caso não haja acesso a Cloudflare/Leaseweb nesta conversa, registrar impedimento e continuar apenas com documentação, testes e código isolado.

## Critério de não promoção

Até a validação integral do SITES-GATE e das evidências correspondentes, **não**:
- enviar dados pessoais de clientes/lead para staging, publicar formulários, instalar serviços com cobrança ou acessar segredos reais;
- criar rotas operacionais expostas, associar domínio de cliente, conceder ALLOW ou ativar rollback automático;
- promover `dev` para `main`, realizar deploy em produção ou anunciar site como `ENTREGUE`.

O Portal mantém autoridade sobre pagamento, briefing, aceite expresso e o evento oficial `ENTREGUE`. A Imagine mantém autoria e gestão dos sites; não há editor self-service do cliente.

**Gates vigentes:** P0-DOC01–14, RF-PEND-01/04, RV-CT, LGPD/AppSec e `config/operational-readiness.json` permanecem BLOCKED. `AGI-11-RB01` desabilitado.
