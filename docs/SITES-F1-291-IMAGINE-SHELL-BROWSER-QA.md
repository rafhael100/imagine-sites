# Imagine Sites F1-291 — QA Chromium da identidade original Imagine

**12/11/2026 — continuação da issue #351 / PR #354; sem merge ou deploy.**

## Por que

Além do contrato estático F1-290, verificar no Chromium instalado no runner como o painel de revisão privado se comporta em 1365, 820, 390 e 320 pixels. O objetivo é verificar tokens, foco, layout e marca em ambiente real, sem conectar à produção.

## Prova técnica

- `SyntheticImagineShellBrowserExportTest` chama somente a rota laboratorial em processo PHP testing com dados fictícios. Exportação exige **APP_ENV=testing, GITHUB_ACTIONS=true e SITES_F291_BROWSER_EXPORT=1**.
- Os quatro HTMLs são gerados em pasta privada temporária, com manifesto SHA-256 e liberação `publishable=false`/`officialClientAcceptance=false`.
- A referência da imagem continua sendo o **PNG original** (blob `678adece...`). Para capturas locais `about:blank`, o logo é convertido em data URI idêntica para não emitir requisição a URL ou alterar o código de produção.
- `ci/f291_imagine_shell_browser.py` inicializa Chromium headless por CDP na máquina CI, sem servidor HTTP: verifica tokens, cabeçalho branco, fundo cinza, item selecionado laranja suave, logo 200px de largura natural, sandbox, noindex, inexistência de scripts/forms, ausência de overflow horizontal e dimensões reais. Captura quatro PNGs diferentes.
- Workflow dedicado salva **somente screenshots e relatório fictícios** por 7 dias para análise visual. HTMLs locais, screenshots e volumes de CI são removidos após o workflow.

## Escopo de segurança

- Não gera, serve ou publica sites. Não altera cores/SEO/HTML dos clientes nem aprova versão/release. A UI Laravel segue route fence de CLI/testing. Não usa DNS, Cloudflare, VPS, clientes, CRM real ou chaves.
- As capturas são **evidência técnica**, não substituem revisão humana de leitura visual, teclado, screen reader e contraste em todos os navegadores.
- O PR #353 é candidato anterior integral 119/119 CI; #354 adiciona shell Node, #F1-291 complementa com navegador. Dez gates operacionais continuam BLOCKED e issue #281 requer proteção de branches.

**Estado: aguardando CI do próprio HEAD e inspeção independente das capturas.**
