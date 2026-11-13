# F1-62 — Contraste limitado do degradê e regressão em 320 pixels

O laboratório F1-59 mede fundo sólido de textos do HTML final de Onepage/Landing nos três presets. Esta etapa acrescenta medição **real do gradiente computado pelo Chromium**, com os textos H1, eyebrow e lead na hero, e um segundo viewport de 320px para cada uma das 12 combinações.

Um único processo Chromium headless CDP lê as cores calculadas: o código exige **linear-gradient com exatamente duas paradas RGB opacas**, sem URL, imagens, opacidade complexa ou cores desconhecidas. Para cada uma das três peças de texto, calcula composição do primeiro plano usando `opacity` computada em nove amostras ao longo das paradas (3 × 9 = 27 medições por página). Reprova qualquer razão abaixo de 4,5:1, limite conservador também para H1 grande. O self-test inclui caso inseguro (texto branco sobre gradiente claro) e rejeição de gradiente alfa não suportado.

Após os testes normais de 390px ou 1365px, o script define **320×740px** sem recarregar conteúdo remoto, verifica `scrollWidth` (até margem de 2px), presença do destino `#contato` e que o botão CTA esteja visível e integralmente dentro da largura.

**Limitações:** as nove amostras sRGB não são prova matemática para todo pixel/subpixel, não verificam opacidade de ancestrais, texto sobre imagens/overlay, pseudo-elementos, contraste de focus outline, espaçamento/click accuracy, layouts em todos os navegadores ou certificação WCAG. Os resultados só cobrem as paletas hardcoded do renderer. O teste não altera as prévias, hashes, conteúdo comercial ou Classic; nenhum serviço, download de navegador, deploy ou cliente.

Usa o workflow existente F1-59; sem segundo Chrome, pacote externo, imagem publicada ou custo extra. PR draft dependente da etapa F1-60 (#81). `dev`, `main`, readiness BLOCKED e gates de aceite/credenciais permanecem inalterados.
