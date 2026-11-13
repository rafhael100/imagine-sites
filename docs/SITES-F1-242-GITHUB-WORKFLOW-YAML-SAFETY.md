# F1-242 — fechar desvio de segurança por extensão `.yaml` no GitHub Actions

**Achado:** o scanner de CI `ci/f21_safety_guard.py` examinava apenas arquivos `.github/workflows/*.yml`, embora o GitHub Actions interprete também `.github/workflows/*.yaml`. Isso permitiria adicionar por engano um workflow novo com extensões aceitas pelo GitHub mas não examinadas pelo controle conservador de permissão/deploy do Sites.

## Correção

- O inventário agora combina `*.yml` **e** `*.yaml`, ordena e deduplica os caminhos.
- Qualquer workflow que seja symlink ou não seja arquivo regular é rejeitado no scanner.
- A verificação original de `permissions: contents: read`, serviços cloud, `id-token: write`, `secrets.*` e ações de deploy permanece ativa em **ambas** as extensões.
- A própria execução do guard cria três fixtures temporárias fora do repositório: `safe.yml`, `safe.yaml` e `unsafe.yaml` contendo um action de deploy fictício. Exige que o inventário encontre as três e que `unsafe.yaml` seja bloqueado. Nenhum arquivo temporário é comitado ou executado pelo GitHub Actions.

**Limite explícito:** é um scanner conservador textual, não prova de branch protection, revisão de permissions efetivas do GitHub, SAST nem auditoria de supply chain. Os dez gates de produção, o PR consolidado e a necessidade de AppSec/DBA permanecem inalterados. Nenhum deploy, token, Cloudflare, VPS, DNS, IAM ou recurso de cliente foi utilizado.
