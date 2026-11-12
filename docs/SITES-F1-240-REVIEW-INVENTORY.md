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

## Renovação F1-265 — 12/11/2026

Nova branch de integração para F1-264/265: recomputar inventário Git completo a partir da base `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. A contagem F1-263 de 658 arquivos/184 sensíveis é histórica e não pode ser reutilizada. Não promover sem dez gates operacionais fechados e revisão independente de Cloudflare Worker e assinatura.

## Atualização F1-267 — 12/11/2026

Reexecutar o inventário completo no HEAD do candidato F1-267, depois da correção de inicialização Chrome e da ampliação dos controles de deploy. Não reutilizar quantidades ou SHA do PR #309. Base `dev` inalterada: `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. Dez gates bloqueados.

## Revisão F1-269 — 12/11/2026

Atualizar o inventário completo no HEAD do candidato F1-269. A referência 672 caminhos/189 sensíveis pertence exclusivamente ao PR #312 e não descreve os arquivos F1-268/269; recalcular o diff Git inteiro contra `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. Sem autorização de merge ou produção.

## Renovação F1-273 — 12/11/2026

Atualizar inventário local Git completo e o SHA a partir do candidato de integração F1-273 e da base fixa `dev` `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. Incluir mudanças no renderizador FAQ, QA DOM, plano editorial, Laravel e testes Chromium. A contagem 678/191 pertence somente ao PR #315; não reutilizar no novo HEAD. Dez gates permanecem BLOCKED.

## Renovação F1-274 — 12/11/2026

Recalcular diff Git completo na branch de integração F1-274 com base `dev` SHA `71b8ffe51139d0e70ed32591b07f295fb7bf5fe3`. O inventário F1-273 de 693 arquivos/195 sensíveis é histórico e deve ser substituído somente por novo resultado desta branch. Incluir arquivo de auditoria FAQ, teste negativo e CI.
