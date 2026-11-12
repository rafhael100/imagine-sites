# F1-240 — inventário **completo** do diff de integração contra dev

Motivação: a comparação GitHub `dev...HEAD` do candidato #277 já alcança centenas de commits. O endpoint de comparação GitHub pode retornar no máximo 300 entradas no array de arquivos; usar essa lista como inventário da integração ocultaria parte do escopo.

## Execução e saída

O script `ci/f240_integration_change_inventory.py` é executado **somente no GitHub Actions**, com checkout do SHA **real** do PR (não o merge commit sintético), histórico Git completo e `origin/dev` coletado apenas para leitura.

O job verifica:

- SHA `dev` exatamente igual à baseline fixada em `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. Se `dev` avançar, o job falha para exigir nova revisão, sem rebasing silencioso.
- `dev` é ancestral exato do candidato; não se admite comparar históricos divergentes sem resolução explícita.
- `git diff --name-status --no-renames -z` lista **todos** os caminhos alterados, sem limite da API e sem truncar nomes; parsing recusa campos ambíguos/duplicados e status inesperados.
- `git ls-tree -r -z HEAD` verifica que arquivos alterados presentes no head não viraram symlinks ou submódulos, e exige presença das correções F1-232–239.
- `config/operational-readiness.json` permanece rigorosamente `BLOCKED` com **dez gates falsos**, usando o validador rígido F1-64 existente.

## Manifesto de revisão

O JSON `imagine-sites-f240-complete-diff-inventory` apresenta SHA de base/head, total real de caminhos, contagens por categoria (`database`, `security`, `integration`, `laravel`, `docs`, `ci` etc.), status A/M/D/T e uma lista completa de caminhos. Registra, separadamente, os caminhos sensíveis (workflows, migrations, integrações, segurança, operação, contratos) que precisam de revisão humana independente. Contém digest SHA-256 da lista **apenas para detecção de alterações no relatório**, não uma assinatura de deploy.

O artefato de relatório fica disponível por **7 dias** no GitHub Actions, sem credenciais e sem modificar o repositório. Ele pode ser usado para distribuir a revisão pelos lotes definidos em `docs/SITES-F1-239-INTEGRATION-CANDIDATE-2026-11-11.md`.

**Não é uma auditoria de conteúdo de segredos, revisão AppSec, controle de branch protection, teste de conformidade, autorização de merge ou de produção.** A CI do candidato #277 e as regras em `AGENTS.md` continuam independentes e obrigatórias. Nenhum acesso a VPS, Portal/CRM reais, Cloudflare, DNS, n8n, IA ou clientes ocorre nesta etapa.

## F1-258 — refresh obrigatório do inventário no candidato atual (12/11/2026)

A integração F1-258 reúne PRs #293–#299. A origem #299 estava 1046 commits à frente de `dev` e zero atrás. **Não reutilizar** a contagem de caminhos nem o SHA de inventários anteriores. Executar o workflow F1-240 no HEAD do candidato de integração desta data, conferir o SHA completo, todos os caminhos e os dez gates BLOCKED; isso não autoriza merge ou deploy.

## Renovação F1-263 — 12/11/2026

A branch de revisão F1-263 reúne #301–#305 sobre a base fixa `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. A contagem anterior de 641 arquivos/177 caminhos sensíveis refere-se a #300, e **não** vale para este novo HEAD. Reexecutar o inventário Git completo, conferir o digest e todos os dez gates `BLOCKED`; sem merge ou go-live.
