# SITES-ARQ-02 — ADR de fronteiras e autoridades

**Natureza:** direção de arquitetura definida; contratos técnicos e operação não homologados. Fonte principal: https://github.com/rafhael100/imagine-portal/pull/94

## Decisão
Sites é motor e banco independentes do Portal. A Imagine produz, versiona e gerencia os sites. O cliente usa o Portal para contratação, briefing, revisão, aceite expresso e atendimento. Não oferecer editor self-service nesta fase.

| Domínio | Autoridade | Vedação |
| --- | --- | --- |
| Conta Portal, contratos, financeiro | Portal e financeiro oficial | Sites/IA não inferem quitação |
| Briefing e aceite da release exata | Portal, ator/tenant autorizado | IA, silêncio ou abrir a prévia não aprovam |
| Releases, manifesto e operação de publicação | Sites + verificador independente | Provedor 202 não prova publicação |
| Evento ENTREGUE e Dia 0 REL-01 | Portal após checklist e provas | Sites não gera entrega oficial sozinho |
| Vínculo Site x CRM | Autoridade versionada conferida nas duas APIs | Frontend não escolhe conta arbitrária |
| T0 | API de captação com persistência durável | Clique do visitante não constitui T0 |
| Lead definitivamente registrado | CRM | n8n não confirma persistência comercial |
| Execução técnica por IA | Sites autoriza, Agents executa | Agente não se autoaprova |
| Landing para Performance | Sites sob contrato próprio | Performance não recebe credenciais Cloudflare |

## Topologia candidata
Portal Laravel/PostgreSQL próprio ⇄ Sites Laravel/PostgreSQL próprio ⇄ GitHub/Cloudflare e Imagine AI Agents. Formulários chamam API de captação principal ou contingência com storage independente e entrega autorizada ao CRM.

- Não compartilhar bancos, SQL nem credenciais. APIs/eventos versionados com identidade/escopo por serviço, recibos verificáveis, revogação, outbox/inbox e idempotência persistente na fase operacional.
- Sites publicados seguem servidos mesmo sem Portal, se infraestrutura de entrega estiver íntegra.
- Falha ao publicar B não faz A deixar de estar no ar; aceite, publicação, health, captação e entrega são estados distintos.
- Desenvolvimento pode usar VPS compartilhada em contêineres isolados após liberação de ambiente; redundância de captação real exige infraestrutura e armazenamento independentes.
- Nunca publicar materiais privados ou dados pessoais em GitHub público.

## Migração sem big-bang
Preservar Node/SQLite legado como fonte de regras/testes, não como SQL ou runtime novo. Preservar branches Laravel e PRs Portal existentes. Preparar adapters isolados após revisão, sem merges automáticos. Segurança, P0 e RF-PEND continuam abertos.
