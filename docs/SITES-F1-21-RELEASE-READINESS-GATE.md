# SITES-F1-21 — Garantias de CI para evitar integração operacional precoce

**Por quê:** os PRs #1–#21 são uma cadeia de laboratórios, não um sistema implantado. Este recorte coloca um inventário explícito de gates ainda **BLOCKED** em `config/operational-readiness.json` e um scanner conservador de workflows para alertar sobre deploy/segredos/ações com permissão de escrita.

## O que o teste verifica

1. Readiness contém ao menos dez gates e todos permanecem com `false`.
2. Todos os workflows existentes declaram `permissions: contents: read`.
3. Nenhum workflow contém ações típicas de autenticação de cloud/deploy, `secrets.*`, `id-token: write` ou permissões de escrita.
4. A rota `/_lab/sites/v1/create-project` continua explicitamente restrita a PHP CLI + ambiente testing + flag e com fontes padrão DENY.
5. Arquivos convencionais de segredos e configuração de deploy não aparecem no repositório.

**Limitações:** scanner baseado em padrões de texto; não é análise completa de supply chain, SBOM, revisão de segurança ou bloqueio de permissões externos. Um maintainer ainda poderia alterar o próprio workflow/teste. A política de branch protection e revisores humanos precisa ser configurada e verificada separadamente por quem controla o repositório. O arquivo `operational-readiness.json` **não** concede autorização para editar gates: registrar `true` exige decisão e prova oficial no processo de change control.

A norma permanece: não fazer merge automático nem deploy, nem operar dados reais, contas Cloudflare/DNS ou contratar fornecedores. A publicação só pode sair do estado bloqueado após homologação dos contratos Portal/CRM/Agents, AppSec/LGPD, custos, backups, credenciais, autoridade do cliente, CDN e aceite específico. AGI-11-RB01 rollback automático desabilitado.
