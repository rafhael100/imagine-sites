# F1-64 — Inventário reproduzível dos bloqueios de homologação

O comando `python3 ci/f64_readiness_inventory.py` lê exclusivamente o arquivo versionado `config/operational-readiness.json` e imprime cada um dos **dez gates operacionais ainda bloqueados**, com breve explicação e link para as issues existentes #33–#38. A versão `--json` produz o mesmo inventário de forma estruturada para revisão em relatórios; `--self-test` executa mutações negativas.

Trata-se de **interpretação de declarações versionadas**, não consulta Leaseweb, Cloudflare, Portal, CRM, AI Agents, nem qualquer secret manager. Nenhum contato do cliente ou PII é processado. A marcação `operationallyVerified=false` e `releaseAuthorized=false` é invariável: passar na verificação significa apenas que o gate **continua protegido**, jamais que produção está habilitada.

O validador confere forma exata do JSON, chaves e tipos dos gates, `status=BLOCKED`, `non_operational=true` e dez valores booleanos `false`. Alterar as regras, afirmar `READY` ou adicionar um gate sem revisão faz o CI recusar. Quando uma evidência real for obtida, o processo de governança deve revisar/configurar o gate sob o responsável e a equipe de segurança; este script não pode conferir essa evidência por conta própria.

A mesma execução do guard F1-21 agora mostra o inventário em todas as branches/PRs, sem criar um novo job de consumo contínuo ou custo adicional. Ações exclusivas do responsável ainda pendentes: acesso autorizado à VPS Leaseweb, Cloudflare/domínio de teste, gestão segura de credenciais e validações reais em homologação, antes de qualquer deployment. **Não enviar segredos a este chat ou GitHub público**.

Nenhuma mudança em `main`, `dev`, VPS, Cloudflare, go-live, identidade, cliente, publicação nem `AGI-11-RB01`. PR draft dependente de F1-63 (#84), gates operacionais permanecem bloqueados.
