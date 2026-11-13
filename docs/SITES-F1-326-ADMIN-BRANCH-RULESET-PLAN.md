# F1-326 — Proteção verificável de branches do Imagine Sites

Estado: **PROPOSTA DE ADMINISTRADOR, NÃO APLICADA**. Issue bloqueadora: [#281](https://github.com/rafhael100/imagine-sites/issues/281). Todos os dez gates de config/operational-readiness.json permanecem false.

## O que foi preparado

ci/f326_branch_ruleset_plan.py gera um payload reproduzível para um ruleset GitHub aplicado somente às branches dev e main. Não depende de tokens no repositório ou workflow, nem altera o GitHub por padrão.

O plano exige:

- PR para integrar, no mínimo **uma revisão independente** e nova aprovação após push relevante;
- nenhuma exceção/bypass pré-configurada;
- impossibilidade de force-push e de apagar branches protegidas;
- status atualizado em relação ao branch base e três checks obrigatórios, confirmados nos commits deste repositório: prevent-accidental-production, pure-domain (8.3), pure-domain (8.4).

Esses três jobs são executados em PRs sem filtro por caminho. NÃO marque workflows condicionais por paths como checks mandatórios: poderão ficar indefinidamente "Pending" em PRs que não alterem esses arquivos.

**Limite de revisão:** a proposta ainda deixa require_code_owner_review=false porque não há CODEOWNERS com equipes/usuários confirmados. Após confirmar revisores AppSec, DBA, Portal/CRM e infraestrutura, configurar CODEOWNERS e regra de revisão de proprietário de código. Autoria da PR não vale como revisão independente; não cadastrar contas fictícias nem autoaprovar.

## Instruções Windows / PowerShell (administrador real)

Na pasta raiz do repositório, com Python 3 e GitHub CLI (gh) instalados:

    python ci/f326_branch_ruleset_plan.py self-test
    python ci/f326_branch_ruleset_plan.py plan > imagine-sites-ruleset-proposto.json
    python ci/f326_branch_ruleset_plan.py audit

O audit é **somente leitura**. Informa se o GitHub reporta quatro tipos de regras ativas em cada branch; não garante valores exatos nem que pushes diretos sejam realmente rejeitados. Renderizar o plano não exige autenticação.

**Só após** o administrador confirmar pelo menos um colaborador externo habilitado a revisar a PR, validar permissões/planos do GitHub e aprovar expressamente a mudança administrativa, a aplicação opcional é:

    python ci/f326_branch_ruleset_plan.py apply --confirm-repository rafhael100/imagine-sites --reviewer-ready --admin-change-approved

- Exige gh autenticado em conta com Administration:write no repositório.
- Falha se já existir ruleset com mesmo nome. Não modifica silenciosamente regras existentes.
- Não aceita operação autenticada em GitHub Actions, mesmo com flags.
- Não imprime nem solicita tokens; utiliza autenticação já existente do GitHub CLI.
- Após aplicar, confere tipos de regras por branch; não declara segurança concluída por esse teste isolado.
- Não faz deploy, merge, alteração de DNS, integração de clientes ou despesas.

## Aceitação final da issue #281

1. Administrador inspeciona rulesets ativos e eventuais regras herdadas, contextos e bypass.
2. Revisor externo real consegue avaliar a PR. Novo push após aprovação força nova revisão.
3. Status checks obrigatórios aparecem com o nome exato e passam em PRs, sem dependência de workflow por paths.
4. Teste administrativo controlado confirma que push direto não autorizado em dev/main é recusado. Nunca usar credenciais de produção em CI/logs.
5. Evidências de configuração e revisão são registradas sem expor token, dados de cliente ou segredos.
6. Proteção de branches não autoriza produção: OP01–OP05 e os dez gates continuam exigindo homologação independente.

Referências oficiais: [GitHub Rulesets](https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets) e [REST rulesets](https://docs.github.com/en/rest/repos/rules).

**Observação:** regras em repositório privado podem depender do plano GitHub aplicável. Não alterar privacidade, plano ou despesas sem decisão do proprietário.
