# OnePage R05 — navegador Chromium real e clique fictício exato (NO-GO)

22/11/2026. Candidata isolada sobre Sites #608 HEAD imutável `d3d5d2b55fe8b005ce4d8626ecc32b866c5877b5`; referências históricas Sites #541/#542, contrato Portal #976/#1003. Sites #605 é outra linha de trabalho; Portal Landing #1087 e Agents #490 não mudam.

## Primeira lacuna após R04

R04 exerceu HTTP loopback e observou os bytes com cliente PHP, não com navegador. R05 utiliza **Chromium headless real** pelo Chrome DevTools Protocol, sem novas dependências de aplicação, e um clique de ponteiro na interface exibida. A prévia é a saída original de `LocalSiteRendering` com os mesmos dados sintéticos versionados da R04. Não reimplementa parser, resposta de IA nem renderização.

## Ensaio e limites explícitos

- Chromium com perfil descartável navega ao PHP em `127.0.0.1`, com CSP, no-store e resolução externa bloqueada. O GET sem cookie é negado; um endpoint de laboratório emite **sessão fictícia**. Não representa login OIDC/MFA nem autorização operacional.
- O navegador carrega o HTML em iframe same-origin; um segundo GET **feito dentro do navegador** lê bytes via `fetch` e calcula SHA-256 em WebCrypto. O SHA deve corresponder ao HTML gerado pelo renderizador.
- O Chrome DevTools injeta `mousePressed`/`mouseReleased` no botão exibido. O servidor local grava um único recibo A; segundo clique produz somente replay idempotente.
- Alterar os bytes efetivos da prévia de A para B invalida a observação anterior; clicar sem nova apresentação dá 409, sem novo efeito. Nova navegação e clique explícito geram recibo B com novo SHA. Sessão fictícia de outro comprador nega GET.
- Ao final, outro processo relê o arquivo local de recibos: exatamente dois efeitos, A e B, SHA diferentes. Diretório de fixtures, perfil Chromium e processos são descartados.

A R05 **não demonstra** identidade nativa, clique legalmente atestado, writer autorizado, armazenamento PG18 operacional, briefing oficial, permissão do revisor, ligação worker/API OpenAI, aceite jurídico ou autorização para publicar. O escritor de arquivo é somente uma fixture descartável, nunca um writer do Portal. R01–R04 não são reexecutadas; Landing permanece independente.

CI: VM efêmera Ubuntu 24.04, PHP 8.4, Node nativo e Chromium instalado apenas no runner descartável via Playwright 1.56.0, conforme precedente Portal #1041. Somente HTTP loopback na aplicação e CSP que nega subrecursos externos; a instalação efêmera do navegador usa pacote público, sem segredos, GitHub `contents: read`, branch/base e SHA fixadas. Nenhuma alteração main/VPS/DNS/Cloudflare, checkout, PSP, OpenAI/Replit, merge/deploy ou publicação. Gate Sites #38 segue NO-GO.

Registrar SHA final, jobs e resultados somente após observação real de CI. Se Chromium não puder fazer HTTP loopback no runner, registrar o erro sem substituir por mock ou alegar prova de navegador.

## Correção de execução CI

A primeira tentativa com Chrome preinstalado foi recusada pelo browser com estado enumerado `CHROME_BLOCKED` ainda no GET sem sessão; não há evidência de clique naquela tentativa. Para evitar depender do Chrome gerenciado da VM e seguir a composição já validada no Portal #1041, esta branch instala Chromium isolado pela versão pinada de Playwright, mantendo perfil efêmero, origem loopback e CSP. Nenhuma mudança em política do navegador gerenciado ou ambiente operacional. Confirmar resultado da nova SHA pelo job dedicado.
