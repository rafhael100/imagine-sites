# Imagine Sites F1-290 — Identidade original Node do Portal no painel interno

**12/11/2026 | Issue #351 | PR draft | Sem deploy ou merge**

## Fonte visual e limites

- Referência canônica: `rafhael100/imagine-portal` PR #589, `design-system/tokens.css` no head `design/imagine-node-original-visual-v1-2026-11-12`.
- O painel de revisão administrativa Laravel em `apps/sites/resources/views/labs/review.blade.php` adotou aliases locais `--imagine-*` para o laranja #F9941D, grafite #171B19, branco #FFFFFF, superfície #F7F7F8, texto laranja acessível #9B4D00 e foco #B75C00.
- O mesmo PNG original `imagine-portal/main/assets/logo-imagine.png` foi importado **byte a byte**, Git blob `678adeceb3a8ecdaeb6b576b9ee18d1bf0396f77`, para `apps/sites/public/assets/logo-imagine.png`; nenhuma imagem foi redesenhada.
- A interface Laravel usa `labs.imagine-shell` após o CSS anterior: menus/tabs, cartões, cabeçalho, visualizador, bordas e tipografia passam a herdar os tokens. Classes e opções da revisão permanecem as mesmas.
- O CSP das 16 rotas laboratoriais que renderizam essa mesma view permite `img-src 'self' data:` para o logo local; nenhuma origem externa, script, formulário ou conexão foi liberada.

## Fronteira explícita com clientes

- O HTML `srcdoc` de Onepages e Landing Pages do cliente permanece gerado exclusivamente pelo motor `StaticSiteRenderer`. Nenhum token `--imagine-*` nem o logotipo interno é injetado nos templates de clientes.
- Os hash/digests dos previews existentes devem permanecer idênticos para as mesmas configurações; temas, cores dos clientes, aprovações internas, revisões e contrato do Portal continuam separados.
- Telas continuam privadas em laboratórios CLI/testing, `noindex`, `no-store`, CSP sem JS/network e fluxos sem publicação.

## Provas necessárias

1. `php tests/f290-imagine-shell-contract.php`: tokens do Portal, SHA exato do PNG, CSP, contraste de texto/foco >=4.5:1, a11y e fronteira de cliente.
2. `php artisan test --filter SyntheticImagineShellIdentityTest` no app Laravel: HTML administrativo com logo e tokens, respostas 404 para acessos não autorizados, `srcdoc` sem marca Imagine e SHA-256 preservado.
3. Workflow dedicado `.github/workflows/sites-f1-290-imagine-shell.yml` em PHP 8.3 e 8.4 e matrizes gerais PHP/Laravel/CI no HEAD exato.
4. Comparação visual em desktop, tablet e celular e validação com revisão humana antes de promover a `dev` (não provar WCAG apenas com testes estáticos).

## Governança

Os dez gates de `config/operational-readiness.json` permanecem BLOCKED; `dev/main` sem proteção administrativa atestada (issue #281), trabalho em branch isolada. Nada de DNS, VPS, Cloudflare, CRM real, dados ou custos; sem ação de aprovação/publicação na interface.
