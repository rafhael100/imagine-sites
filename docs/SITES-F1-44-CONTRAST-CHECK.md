# F1-44 — Contraste de texto da revisão visual em Chromium

Identificamos contraste inferior a 4,5:1 em parte da tipografia secundária do protótipo F1-38/39/40. A etapa altera apenas as cores da interface demonstrativa no Blade, mantendo o HTML do site gerado e seus hashes inalterados.

Mudanças de tom: `#697b90`, `#718399`, `#68788e`, `#60748a` e `#7a8aa0` em textos pequenos foram normalizados para `#52647a`, sobre fundo branco ou claro. O texto em fundos escuros e os estados de atenção foram mantidos.

O script `ci/f44_contrast.py`, chamado pelo mesmo Chrome DevTools privado da etapa F1-43, extrai cores calculadas pelo navegador para 16 grupos de seletores representativos, encontrando a cor de fundo sólida no elemento ou seu ancestral. Calcula luminância sRGB e razão de contraste segundo a fórmula WCAG e **reprova** qualquer elemento amostrado abaixo de 4,5:1. Executa sobre as quatro combinações de produto/dispositivo.

Limitações: é uma checagem amostral de texto sem transparência, não valida todo o contraste (imagens, gradientes, foco, ícones, hover), nem substitui auditoria WCAG ou testes humanos. O script falha se uma cor RGBA não opaca exigir composição de fundo não suportada.

CI continua sem publicar arquivos ou screenshots, sem novas dependências e sem Chrome remoto. Não há aceite ou integração real Portal/CRM. `main`, VPS, Cloudflare, DNS, custos e readiness permanecem inalterados. PR draft depende da etapa F1-43 (#61).
